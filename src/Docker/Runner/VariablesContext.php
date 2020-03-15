<?php

namespace Keboola\DockerBundle\Docker\Runner;

class VariablesContext extends \ArrayObject
{
    public function __construct(array $configurationRow)
    {
        parent::__construct($configurationRow);
    }
}
