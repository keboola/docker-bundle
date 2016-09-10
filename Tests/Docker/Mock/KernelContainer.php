<?php

namespace Keboola\DockerBundle\Tests\Docker\Mock;

class KernelContainer
{
    private $service;

    public function set($service)
    {
        $this->service = $service;
    }

    public function get($dummy)
    {
        return $this->service;
    }
}
