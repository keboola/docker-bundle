<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class SharedCodeRow extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('configuration');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
                ->scalarNode('variables_id')->end()
                ->arrayNode('code_content')
                    ->beforeNormalization()
                    ->ifString()
                        // phpcs:ignore
                        ->then( function ($v) { return [$v]; } )
                    ->end()
                    ->prototype('scalar')->end()
                ->end()
            ->end();
        return $treeBuilder;
    }
}
