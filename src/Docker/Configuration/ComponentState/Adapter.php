<?php

namespace Keboola\DockerBundle\Docker\Configuration\ComponentState;

use Keboola\DockerBundle\Docker\Configuration;

class Adapter extends Configuration\Adapter
{
    protected $configClass = Configuration\ComponentState::class;

    protected function normalizeConfig($config)
    {
        return $config;
    }
}
