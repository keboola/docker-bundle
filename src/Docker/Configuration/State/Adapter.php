<?php

namespace Keboola\DockerBundle\Docker\Configuration\State;

use Keboola\DockerBundle\Docker\Configuration;

class Adapter extends Configuration\Adapter
{
    protected $configClass = Configuration\State::class;

    protected function normalizeConfig($config)
    {
        return $config;
    }
}
