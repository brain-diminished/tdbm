<?php


namespace Mouf\Database\TDBM\Utils;

use Mouf\Database\TDBM\ConfigurationInterface;

/**
 * Listens to events when DAOs and beans are generated and act accordingly on those.
 */
interface GeneratorListenerInterface
{
    /**
     * @param ConfigurationInterface $configuration
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    public function onGenerate(ConfigurationInterface $configuration, array $beanDescriptors) : void;
}
