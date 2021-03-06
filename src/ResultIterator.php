<?php

namespace TheCodingMachine\TDBM;

use Doctrine\DBAL\Statement;
use Mouf\Database\MagicQuery;
use TheCodingMachine\TDBM\QueryFactory\QueryFactory;
use Porpaginas\Result;
use Psr\Log\LoggerInterface;
use Traversable;

/*
 Copyright (C) 2006-2017 David Négrier - THE CODING MACHINE

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Iterator used to retrieve results.
 */
class ResultIterator implements Result, \ArrayAccess, \JsonSerializable
{
    /**
     * @var Statement
     */
    protected $statement;

    private $objectStorage;
    private $className;

    private $tdbmService;
    private $parameters;
    private $magicQuery;

    /**
     * @var QueryFactory
     */
    private $queryFactory;

    /**
     * @var InnerResultIterator
     */
    private $innerResultIterator;

    private $databasePlatform;

    private $totalCount;

    private $mode;

    private $logger;

    public function __construct(QueryFactory $queryFactory, array $parameters, $objectStorage, $className, TDBMService $tdbmService, MagicQuery $magicQuery, $mode, LoggerInterface $logger)
    {
        if ($mode !== null && $mode !== TDBMService::MODE_CURSOR && $mode !== TDBMService::MODE_ARRAY) {
            throw new TDBMException("Unknown fetch mode: '".$mode."'");
        }

        $this->queryFactory = $queryFactory;
        $this->objectStorage = $objectStorage;
        $this->className = $className;
        $this->tdbmService = $tdbmService;
        $this->parameters = $parameters;
        $this->magicQuery = $magicQuery;
        $this->databasePlatform = $this->tdbmService->getConnection()->getDatabasePlatform();
        $this->mode = $mode;
        $this->logger = $logger;
    }

    protected function executeCountQuery(): void
    {
        $sql = $this->magicQuery->build($this->queryFactory->getMagicSqlCount(), $this->parameters);
        $this->logger->debug('Running count query: '.$sql);
        $this->totalCount = (int) $this->tdbmService->getConnection()->fetchColumn($sql, $this->parameters);
    }

    /**
     * Counts found records (this is the number of records fetched, taking into account the LIMIT and OFFSET settings).
     *
     * @return int
     */
    public function count(): int
    {
        if ($this->totalCount === null) {
            $this->executeCountQuery();
        }

        return $this->totalCount;
    }

    /**
     * Casts the result set to a PHP array.
     *
     * @return array
     */
    public function toArray()
    {
        return iterator_to_array($this->getIterator());
    }

    /**
     * Returns a new iterator mapping any call using the $callable function.
     *
     * @param callable $callable
     *
     * @return MapIterator
     */
    public function map(callable $callable)
    {
        return new MapIterator($this->getIterator(), $callable);
    }

    /**
     * Retrieve an external iterator.
     *
     * @link http://php.net/manual/en/iteratoraggregate.getiterator.php
     *
     * @return InnerResultIterator An instance of an object implementing <b>Iterator</b> or
     *                             <b>Traversable</b>
     *
     * @since 5.0.0
     */
    public function getIterator()
    {
        if ($this->innerResultIterator === null) {
            if ($this->mode === TDBMService::MODE_CURSOR) {
                $this->innerResultIterator = new InnerResultIterator($this->queryFactory->getMagicSql(), $this->parameters, null, null, $this->queryFactory->getColumnDescriptors(), $this->objectStorage, $this->className, $this->tdbmService, $this->magicQuery, $this->logger);
            } else {
                $this->innerResultIterator = new InnerResultArray($this->queryFactory->getMagicSql(), $this->parameters, null, null, $this->queryFactory->getColumnDescriptors(), $this->objectStorage, $this->className, $this->tdbmService, $this->magicQuery, $this->logger);
            }
        }

        return $this->innerResultIterator;
    }

    /**
     * @param int $offset
     * @param int $limit
     *
     * @return PageIterator
     */
    public function take($offset, $limit)
    {
        return new PageIterator($this, $this->queryFactory->getMagicSql(), $this->parameters, $limit, $offset, $this->queryFactory->getColumnDescriptors(), $this->objectStorage, $this->className, $this->tdbmService, $this->magicQuery, $this->mode, $this->logger);
    }

    /**
     * Whether a offset exists.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     *
     * @param mixed $offset <p>
     *                      An offset to check for.
     *                      </p>
     *
     * @return bool true on success or false on failure.
     *              </p>
     *              <p>
     *              The return value will be casted to boolean if non-boolean was returned
     *
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        return $this->getIterator()->offsetExists($offset);
    }

    /**
     * Offset to retrieve.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     *
     * @param mixed $offset <p>
     *                      The offset to retrieve.
     *                      </p>
     *
     * @return mixed Can return all value types
     *
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        return $this->getIterator()->offsetGet($offset);
    }

    /**
     * Offset to set.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     *
     * @param mixed $offset <p>
     *                      The offset to assign the value to.
     *                      </p>
     * @param mixed $value  <p>
     *                      The value to set.
     *                      </p>
     *
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        return $this->getIterator()->offsetSet($offset, $value);
    }

    /**
     * Offset to unset.
     *
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     *
     * @param mixed $offset <p>
     *                      The offset to unset.
     *                      </p>
     *
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        return $this->getIterator()->offsetUnset($offset);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     *
     * @param bool $stopRecursion Parameter used internally by TDBM to
     *                            stop embedded objects from embedding
     *                            other objects
     *
     * @return mixed data which can be serialized by <b>json_encode</b>,
     *               which is a value of any type other than a resource
     *
     * @since 5.4.0
     */
    public function jsonSerialize($stopRecursion = false)
    {
        return array_map(function (AbstractTDBMObject $item) use ($stopRecursion) {
            return $item->jsonSerialize($stopRecursion);
        }, $this->toArray());
    }

    /**
     * Returns only one value (the first) of the result set.
     * Returns null if no value exists.
     *
     * @return mixed|null
     */
    public function first()
    {
        $page = $this->take(0, 1);
        foreach ($page as $bean) {
            return $bean;
        }

        return;
    }

    /**
     * Sets the ORDER BY directive executed in SQL and returns a NEW ResultIterator.
     *
     * For instance:
     *
     *  $resultSet = $resultSet->withOrder('label ASC, status DESC');
     *
     * **Important:** TDBM does its best to protect you from SQL injection. In particular, it will only allow column names in the "ORDER BY" clause. This means you are safe to pass input from the user directly in the ORDER BY parameter.
     * If you want to pass an expression to the ORDER BY clause, you will need to tell TDBM to stop checking for SQL injections. You do this by passing a `UncheckedOrderBy` object as a parameter:
     *
     *  $resultSet->withOrder(new UncheckedOrderBy('RAND()'))
     *
     * @param string|UncheckedOrderBy|null $orderBy
     *
     * @return ResultIterator
     */
    public function withOrder($orderBy) : ResultIterator
    {
        $clone = clone $this;
        $clone->queryFactory = clone $this->queryFactory;
        $clone->queryFactory->sort($orderBy);
        $clone->innerResultIterator = null;

        return $clone;
    }

    /**
     * Sets new parameters for the SQL query and returns a NEW ResultIterator.
     *
     * For instance:
     *
     *  $resultSet = $resultSet->withParameters('label ASC, status DESC');
     *
     * @param string|UncheckedOrderBy|null $orderBy
     *
     * @return ResultIterator
     */
    public function withParameters(array $parameters) : ResultIterator
    {
        $clone = clone $this;
        $clone->parameters = $parameters;
        $clone->innerResultIterator = null;
        $clone->totalCount = null;

        return $clone;
    }
}
