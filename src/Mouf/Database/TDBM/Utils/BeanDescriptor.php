<?php


namespace Mouf\Database\TDBM\Utils;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Mouf\Database\SchemaAnalyzer\SchemaAnalyzer;
use Mouf\Database\TDBM\TDBMException;

/**
 * This class represents a bean
 */
class BeanDescriptor
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var SchemaAnalyzer
     */
    private $schemaAnalyzer;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var AbstractBeanPropertyDescriptor[]
     */
    private $beanPropertyDescriptors = [];

    public function __construct(Table $table, SchemaAnalyzer $schemaAnalyzer, Schema $schema) {
        $this->table = $table;
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->schema = $schema;
        $this->initBeanPropertyDescriptors();
    }

    private function initBeanPropertyDescriptors() {
        $this->beanPropertyDescriptors = $this->getProperties($this->table);
    }

    /**
     * Returns the foreignkey the column is part of, if any. null otherwise.
     *
     * @param Table $table
     * @param Column $column
     * @return ForeignKeyConstraint|null
     */
    private function isPartOfForeignKey(Table $table, Column $column) {
        $localColumnName = $column->getName();
        foreach ($table->getForeignKeys() as $foreignKey) {
            foreach ($foreignKey->getColumns() as $columnName) {
                if ($columnName === $localColumnName) {
                    return $foreignKey;
                }
            }
        }
        return null;
    }

    /**
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getBeanPropertyDescriptors()
    {
        return $this->beanPropertyDescriptors;
    }

    /**
     * Returns the list of columns that are not nullable and not autogenerated for a given table and its parent.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getConstructorProperties() {

        $constructorProperties = array_filter($this->beanPropertyDescriptors, function(AbstractBeanPropertyDescriptor $property) {
           return $property->isCompulsory();
        });

        return $constructorProperties;
    }

    /**
     * Returns the list of properties exposed as getters and setters in this class.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getExposedProperties() {
        $exposedProperties = array_filter($this->beanPropertyDescriptors, function(AbstractBeanPropertyDescriptor $property) {
            return $property->getTable()->getName() == $this->table->getName();
        });

        return $exposedProperties;
    }

    /**
     * Returns the list of foreign keys pointing to the table represented by this bean, excluding foreign keys
     * from junction tables and from inheritance.
     *
     * @return ForeignKeyConstraint[]
     */
    public function getIncomingForeignKeys() {

        $junctionTables = $this->schemaAnalyzer->detectJunctionTables();
        $junctionTableNames = array_map(function(Table $table) { return $table->getName(); }, $junctionTables);
        $childrenRelationships = $this->schemaAnalyzer->getChildrenRelationships($this->table->getName());

        $fks = [];
        foreach ($this->schema->getTables() as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                if ($fk->getForeignTableName() === $this->table->getName()) {
                    if (in_array($fk->getLocalTableName(), $junctionTableNames)) {
                        continue;
                    }
                    foreach ($childrenRelationships as $childFk) {
                        if ($fk->getLocalTableName() === $childFk->getLocalTableName() && $fk->getLocalColumns() === $childFk->getLocalColumns()) {
                            continue 2;
                        }
                    }
                    $fks[] = $fk;
                }
            }
        }

        return $fks;
    }

    /**
     * Returns the list of properties for this table (including parent tables).
     *
     * @param Table $table
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getProperties(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $parentTable = $this->schema->getTable($parentRelationship->getForeignTableName());
            $properties = $this->getProperties($parentTable);
            // we merge properties by overriding property names.
            $localProperties = $this->getPropertiesForTable($table);
            foreach ($localProperties as $name => $property) {
                // We do not override properties if this is a primary key!
                if ($property->isPrimaryKey()) {
                    continue;
                }
                $properties[$name] = $property;
            }
            //$properties = array_merge($properties, $localProperties);

        } else {
            $properties = $this->getPropertiesForTable($table);
        }

        return $properties;
    }

        /**
     * Returns the list of properties for this table (ignoring parent tables).
     *
     * @param Table $table
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getPropertiesForTable(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $ignoreColumns = $parentRelationship->getLocalColumns();
        } else {
            $ignoreColumns = [];
        }

        $beanPropertyDescriptors = [];

        foreach ($table->getColumns() as $column) {
            if (array_search($column->getName(), $ignoreColumns) !== false) {
                continue;
            }

            $fk = $this->isPartOfForeignKey($table, $column);
            if ($fk !== null) {
                // Check that previously added descriptors are not added on same FK (can happen with multi key FK).
                foreach ($beanPropertyDescriptors as $beanDescriptor) {
                    if ($beanDescriptor instanceof ObjectBeanPropertyDescriptor && $beanDescriptor->getForeignKey() === $fk) {
                        continue 2;
                    }
                }
                // Check that this property is not an inheritance relationship
                $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
                if ($parentRelationship === $fk) {
                    continue;
                }

                $beanPropertyDescriptors[] = new ObjectBeanPropertyDescriptor($table, $fk, $this->schemaAnalyzer);
            } else {
                $beanPropertyDescriptors[] = new ScalarBeanPropertyDescriptor($table, $column);
            }
        }

        // Now, let's get the name of all properties and let's check there is no duplicate.
        /** @var $names AbstractBeanPropertyDescriptor[] */
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getUpperCamelCaseName();
            if (isset($names[$name])) {
                $names[$name]->useAlternativeName();
                $beanDescriptor->useAlternativeName();
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Final check (throw exceptions if problem arises)
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getUpperCamelCaseName();
            if (isset($names[$name])) {
                throw new TDBMException("Unsolvable name conflict while generating method name");
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Last step, let's rebuild the list with a map:
        $beanPropertyDescriptorsMap = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $beanPropertyDescriptorsMap[$beanDescriptor->getLowerCamelCaseName()] = $beanDescriptor;
        }

        return $beanPropertyDescriptorsMap;
    }

    public function generateBeanConstructor() {
        $constructorProperties = $this->getConstructorProperties();

        $constructorCode = "    /**
     * The constructor takes all compulsory arguments.
     *
%s
     */
    public function __construct(%s) {
%s%s
    }
    ";

        $paramAnnotations = [];
        $arguments = [];
        $assigns = [];
        $parentConstructorArguments = [];

        foreach ($constructorProperties as $property) {
            $className = $property->getClassName();
            if ($className) {
                $arguments[] = $className.' '.$property->getVariableName();
            } else {
                $arguments[] = $property->getVariableName();
            }
            $paramAnnotations[] = $property->getParamAnnotation();
            if ($property->getTable()->getName() === $this->table->getName()) {
                $assigns[] = $property->getConstructorAssignCode();
            } else {
                $parentConstructorArguments[] = $property->getVariableName();
            }
        }

        $parentConstrutorCode = sprintf("        parent::__construct(%s);\n", implode(', ', $parentConstructorArguments));

        return sprintf($constructorCode, implode("\n", $paramAnnotations), implode(", ", $arguments), $parentConstrutorCode, implode("\n", $assigns));
    }

    public function generateDirectForeignKeysCode() {
        $fks = $this->getIncomingForeignKeys();

        $fksByTable = [];

        foreach ($fks as $fk) {
            $fksByTable[$fk->getLocalTableName()][] = $fk;
        }

        /* @var $fksByMethodName ForeignKeyConstraint[] */
        $fksByMethodName = [];

        foreach ($fksByTable as $tableName => $fksForTable) {
            if (count($fksForTable) > 1) {
                foreach ($fksForTable as $fk) {
                    $methodName = 'get'.TDBMDaoGenerator::toCamelCase($fk->getLocalTableName()).'By';

                    $camelizedColumns = array_map(['Mouf\\Database\\TDBM\\Utils\\TDBMDaoGenerator', 'toCamelCase'], $fk->getLocalColumns());

                    $methodName .= implode('And', $camelizedColumns);

                    $fksByMethodName[$methodName] = $fk;
                }
            } else {
                $methodName = 'get'.TDBMDaoGenerator::toCamelCase($fksForTable[0]->getLocalTableName());
                $fksByMethodName[$methodName] = $fk;
            }
        }

        $code = '';

        foreach ($fksByMethodName as $methodName => $fk) {
            $getterCode = '    /**
     * Returns the list of %s pointing to this bean via the %s column.
     *
     * @return %s[]|Resultiterator
     */
    public function %s()
    {
        return $this->tdbmService->findObjects(%s, %s, %s);
    }

';

            list($sql, $parametersCode) = $this->getFilters($fk);

            $beanClass = TDBMDaoGenerator::getBeanNameFromTableName($fk->getLocalTableName());
            $code .= sprintf($getterCode,
                $beanClass,
                implode(', ', $fk->getColumns()),
                $beanClass,
                $methodName,
                var_export($fk->getLocalTableName(), true),
                $sql,
                $parametersCode
            );
        }

        return $code;
    }

    private function getFilters(ForeignKeyConstraint $fk) {
        $sqlParts = [];
        $counter = 0;
        $parameters = [];

        $pkColumns = $this->table->getPrimaryKeyColumns();

        foreach ($fk->getLocalColumns() as $columnName) {
            $paramName = "tdbmparam".$counter;
            $sqlParts[] = $fk->getLocalTableName().'.'.$columnName." = :".$paramName;

            $pkColumn = $pkColumns[$counter];
            $parameters[] = sprintf('%s => $this->get(%s, %s)', var_export($paramName, true), var_export($pkColumn, true), var_export($this->table->getName(), true));
            $counter++;
        }
        $sql = "'".implode(' AND ', $sqlParts)."'";
        $parametersCode = '[ '.implode(', ', $parameters).' ]';

        return [$sql, $parametersCode];
    }

    /**
     * Generate code section about pivot tables
     *
     * @return string;
     */
    public function generatePivotTableCode() {
        $descs = [];
        foreach ($this->schemaAnalyzer->detectJunctionTables() as $table) {
            // There are exactly 2 FKs since this is a pivot table.
            $fks = array_values($table->getForeignKeys());

            if ($fks[0]->getForeignTableName() === $this->table->getName()) {
                $localFK = $fks[0];
                $remoteFK = $fks[1];
            } elseif ($fks[1]->getForeignTableName() === $this->table->getName()) {
                $localFK = $fks[1];
                $remoteFK = $fks[0];
            } else {
                continue;
            }

            $descs[$remoteFK->getForeignTableName()][] = [
                'table' => $table,
                'localFK' => $localFK,
                'remoteFK' => $remoteFK
            ];

        }

        $finalDescs = [];
        foreach ($descs as $descArray) {
            if (count($descArray) > 1) {
                foreach ($descArray as $desc) {
                    $desc['name'] = TDBMDaoGenerator::toCamelCase($desc['remoteFK']->getForeignTableName())."By".TDBMDaoGenerator::toCamelCase($desc['table']->getName());
                    $finalDescs[] = $desc;
                }
            } else {
                $desc = $descArray[0];
                $desc['name'] = TDBMDaoGenerator::toCamelCase($desc['remoteFK']->getForeignTableName());
                $finalDescs[] = $desc;
            }
        }


        $code = '';

        foreach ($finalDescs as $desc) {
            $code .= $this->getPivotTableCode($desc['name'], $desc['table'], $desc['localFK'], $desc['remoteFK']);
        }

        return $code;
    }

    public function getPivotTableCode($name, Table $table, ForeignKeyConstraint $localFK, ForeignKeyConstraint $remoteFK) {
        $singularName = TDBMDaoGenerator::toSingular($name);
        $remoteBeanName = TDBMDaoGenerator::getBeanNameFromTableName($remoteFK->getForeignTableName());
        $variableName = '$'.TDBMDaoGenerator::toVariableName($remoteBeanName);

        $str = '    /**
     * Returns the list of %s associated to this bean via the %s pivot table.
     *
     * @return %s[]
     */
    public function get%s() {
        return $this->_getRelationships(%s);
    }
';

        $getterCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $name, var_export($remoteFK->getLocalTableName(), true));

        $str = '    /**
     * Adds a relationship with %s associated to this bean via the %s pivot table.
     *
     * @param %s %s
     */
    public function add%s(%s %s) {
        return $this->addRelationship(%s, %s);
    }
';

        $adderCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);

        $str = '    /**
     * Deletes the relationship with %s associated to this bean via the %s pivot table.
     *
     * @param %s %s
     */
    public function remove%s(%s %s) {
        return $this->_removeRelationship(%s, %s);
    }
';

        $removerCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);

        $str = '    /**
     * Returns whether this bean is associated with %s via the %s pivot table.
     *
     * @param %s %s
     * @return bool
     */
    public function has%s(%s %s) {
        return $this->hasRelationship(%s, %s);
    }
';

        $hasCode = sprintf($str, $remoteBeanName, $table->getName(), $remoteBeanName, $variableName, $singularName, $remoteBeanName, $variableName, var_export($remoteFK->getLocalTableName(), true), $variableName);


        $code = $getterCode.$adderCode.$removerCode.$hasCode;

        return $code;
    }

    /**
     * Writes the PHP bean file with all getters and setters from the table passed in parameter.
     *
     * @param string $beannamespace The namespace of the bean
     */
    public function generatePhpCode($beannamespace) {
        $baseClassName = TDBMDaoGenerator::getBaseBeanNameFromTableName($this->table->getName());
        $className = TDBMDaoGenerator::getBeanNameFromTableName($this->table->getName());
        $tableName = $this->table->getName();

        $parentFk = $this->schemaAnalyzer->getParentRelationship($tableName);
        if ($parentFk !== null) {
            $extends = TDBMDaoGenerator::getBeanNameFromTableName($parentFk->getForeignTableName());
            $use = "";
        } else {
            $extends = "AbstractTDBMObject";
            $use = "use Mouf\\Database\\TDBM\\AbstractTDBMObject;\n\n";
        }

        $str = "<?php
namespace {$beannamespace};

use Mouf\\Database\\TDBM\\ResultIterator;
$use
/*
 * This file has been automatically generated by TDBM.
 * DO NOT edit this file, as it might be overwritten.
 * If you need to perform changes, edit the $className class instead!
 */

/**
 * The $baseClassName class maps the '$tableName' table in database.
 */
class $baseClassName extends $extends
{
";

        $str .= $this->generateBeanConstructor();



        foreach ($this->getExposedProperties() as $property) {
            $str .= $property->getGetterSetterCode();
        }

        $str .= $this->generateDirectForeignKeysCode();
        $str .= $this->generatePivotTableCode();

        $str .= "}
";
        return $str;
    }
}