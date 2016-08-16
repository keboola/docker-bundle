<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\InputMapping\Configuration\File;
use Keboola\InputMapping\Configuration\Table;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Container extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("container");
        // System
        $root
            ->children()
                ->variableNode("parameters")->end()
                ->variableNode("runtime")->end()
                ->variableNode("image_parameters")
        ;
        $storage = $root
            ->children()
                ->arrayNode("storage");

        $input = $storage
            ->children()
                ->arrayNode("input");

        $inputTable = $input
            ->children()
                ->arrayNode("tables")
                    ->prototype("array")
        ;
        Table::configureNode($inputTable);

        $inputFile = $input
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        File::configureNode($inputFile);

        $output = $storage
            ->children()
                ->arrayNode("output");

        $outputTable = $output
            ->children()
                ->arrayNode("tables")
                    ->prototype("array")
        ;
        Output\Table::configureNode($outputTable);

        $outputFile = $output
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        Output\File::configureNode($outputFile);

        // authorization
        $root->children()
            ->arrayNode("authorization")
            ->children()
                ->arrayNode("oauth_api")
                ->children()
                    ->scalarNode("id")->end()
                    ->variableNode("credentials")
                ->end()
            ->end()
        ->end();

        // action
        $root->children()->scalarNode("action")->end();

        // processors
        $root->children()
            ->arrayNode('processors')
                ->children()
                    ->arrayNode('before')
                        ->prototype('array')
                            ->children()
                                ->variableNode('parameters')->end()
                                ->arrayNode('definition')
                                    ->children()
                                        ->scalarNode('component')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->arrayNode('before')
                        ->prototype('array')
                            ->children()
                                ->variableNode('parameters')->end()
                                ->arrayNode('definition')
                                    ->children()
                                        ->scalarNode('component')->end()
                                    ->end()
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
