<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class State extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root('state');
        $root->children()
            ->arrayNode(StateFile::NAMESPACE_COMPONENT)->prototype("variable")->end()->end()
            ->arrayNode(StateFile::NAMESPACE_STORAGE)
                ->children()
                    ->arrayNode(StateFile::NAMESPACE_INPUT)
                        ->children()
                            ->arrayNode(StateFile::NAMESPACE_TABLES)
                                ->children()
                                    ->scalarNode('source')->end()
                                    ->scalarNode('lastImportDate')->end()
                                ->end()
                            ->end()

                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();
        return $treeBuilder;
    }
}
