<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration\Usage;

use Keboola\DockerBundle\Docker\Configuration;

class Adapter extends Configuration\Adapter
{
    protected $configClass = Configuration\Usage::class;
}
