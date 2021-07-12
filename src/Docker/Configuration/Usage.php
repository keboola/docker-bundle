<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Keboola\DockerBundle\Docker\Configuration;

class Usage extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('usage');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->prototype('array')
                ->children()
                    ->scalarNode('metric')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                    ->scalarNode('value')
                        ->isRequired()
                        ->cannotBeEmpty()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
