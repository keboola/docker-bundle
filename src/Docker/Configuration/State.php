<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\State\StateSpec;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class State extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return (new StateSpec())->getConfigTreeBuilder();
    }
}
