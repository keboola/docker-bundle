<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

class SharedCodeContext
{
    private $values = [];

    public function pushValue($key, $value)
    {
        $this->values[$key] = $value;
    }

    public function getKeys()
    {
        return array_keys($this->values);
    }

    public function __isset($name)
    {
        return isset($this->values[$name]);
    }

    public function __get($name)
    {
        return $this->values[$name];
    }
}
