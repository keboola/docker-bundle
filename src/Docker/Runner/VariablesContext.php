<?php

namespace Keboola\DockerBundle\Docker\Runner;

class VariablesContext extends \ArrayObject
{
    public function __construct(array $configurationRow)
    {
        $values = [];
        foreach ($configurationRow['values'] as $row) {
            $values[$row['name']] = $row['value'];
        }
        parent::__construct($values);
    }
}
