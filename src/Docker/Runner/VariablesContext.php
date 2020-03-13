<?php

namespace Keboola\DockerBundle\Docker;

class VariablesContext extends \ArrayObject
{
    protected $id;
    protected $name;

    public function __construct(array $configurationRow)
    {
        $this->id = $configurationRow['id'];
        $this->name = $configurationRow['name'];
        parent::__construct($configurationRow['configuration']);
    }
}