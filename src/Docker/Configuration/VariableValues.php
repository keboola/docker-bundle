<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Keboola\DockerBundle\Docker\Configuration;

class VariableValues extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder;
        $rootNode = $treeBuilder->root('configuration');
        $rootNode
            ->arrayNode('variables')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('id')->end()
                        ->scalarNode('name')->end()
                        ->arrayNode('configuration')->ignoreExtraKeys(false)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
