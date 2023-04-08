<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ImageSpec;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Image extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        return (new ImageSpec())->getConfigTreeBuilder();
    }
}
