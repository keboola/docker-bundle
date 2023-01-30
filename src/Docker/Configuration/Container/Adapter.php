<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration\Container;

use Keboola\DockerBundle\Docker\Configuration;

class Adapter extends Configuration\Adapter
{
    protected $configClass = Configuration\Container::class;
}
