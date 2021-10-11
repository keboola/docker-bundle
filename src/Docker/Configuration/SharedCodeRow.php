<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Keboola\DockerBundle\Docker\Configuration;

class SharedCodeRow extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder;
        $rootNode = $treeBuilder->root('configuration');
        $rootNode
            ->children()
                ->scalarNode('variables_id')->end()
                ->arrayNode('code_content')
                    ->beforeNormalization()
                    ->ifString()
                        // phpcs:ignore
                        ->then( function ($v) { return [$v]; } )
                    ->end()
                    ->prototype("scalar")->end()
                ->end()
            ->end();
        return $treeBuilder;
    }
}
