<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Adapter;

class UsageFileAdapter extends Adapter
{
    protected $configClass = UsageFileConfigDefinition::class;
}
