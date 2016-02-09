<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
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
        Input\Table::configureNode($inputTable);

        $inputFile = $input
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        Input\File::configureNode($inputFile);

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
                    ->variableNode("credentials");

        return $treeBuilder;
    }
}
