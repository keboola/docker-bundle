<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\InputMapping\Configuration\File as InputFile;
use Keboola\InputMapping\Configuration\Table as InputTable;
use Keboola\OutputMapping\Configuration\File as OutputFile;
use Keboola\OutputMapping\Configuration\Table as OutputTable;
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
        InputTable::configureNode($inputTable);

        $inputFile = $input
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        InputFile::configureNode($inputFile);

        $output = $storage
            ->children()
                ->arrayNode("output");

        $outputTable = $output
            ->children()
                ->arrayNode("tables")
                    ->prototype("array")
        ;
        OutputTable::configureNode($outputTable);

        $outputFile = $output
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        OutputFile::configureNode($outputFile);

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
                    ->arrayNode('after')
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
