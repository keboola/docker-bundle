<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

abstract class Configuration implements ConfigurationInterface
{
    final public function __construct()
    {
    }

    /**
     * Shortcut method for processing configurations
     *
     * @param $configurations
     * @return array
     */
    public function parse($configurations)
    {
        $processor = new Processor();
        $definition = new static();
        return $processor->processConfiguration($definition, $configurations);
    }
}
