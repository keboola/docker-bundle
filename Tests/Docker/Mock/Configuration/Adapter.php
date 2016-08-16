<?php

namespace Keboola\DockerBundle\Tests\Docker\Mock\Configuration;

use Keboola\DockerBundle\Tests\Docker\Mock\Configuration;

class Adapter extends \Keboola\DockerBundle\Docker\Configuration\Adapter
{
    protected $configClass = Configuration::class;
}
