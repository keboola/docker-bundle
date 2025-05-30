<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class State extends Configuration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('state');
        $root = $treeBuilder->getRootNode();
        $root->children()
            ->arrayNode(StateFile::NAMESPACE_COMPONENT)->prototype('variable')->end()->end()
            ->arrayNode(StateFile::NAMESPACE_STORAGE)
                ->children()
                    ->arrayNode(StateFile::NAMESPACE_INPUT)
                        ->children()
                            ->arrayNode(StateFile::NAMESPACE_TABLES)
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('source')->isRequired()->end()
                                        ->scalarNode('lastImportDate')->isRequired()->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode(StateFile::NAMESPACE_FILES)
                                ->prototype('array')
                                    ->children()
                                        ->arrayNode('tags')->isRequired()
                                            ->prototype('array')
                                                ->children()
                                                    ->scalarNode('name')->end()
                                                    ->scalarNode('match')->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                        ->scalarNode('lastImportId')->isRequired()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode(StateFile::NAMESPACE_DATA_APP)
                ->ignoreExtraKeys(false)
            ->end()
        ->end();
        return $treeBuilder;
    }
}
