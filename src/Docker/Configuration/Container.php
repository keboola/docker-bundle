<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\ConfigurationSpec;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Container extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return (new ConfigurationSpec())->getConfigTreeBuilder();
    }
}
