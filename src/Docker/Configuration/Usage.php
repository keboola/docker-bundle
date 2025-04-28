<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Usage extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
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
