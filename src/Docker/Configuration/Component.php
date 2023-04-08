<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpec;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Component extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return (new ComponentSpec())->getConfigTreeBuilder();
    }
}
