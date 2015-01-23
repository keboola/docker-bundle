<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Container implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("container");
        // System
        $root
            ->children()
                ->arrayNode("system")
                    ->children()
                        ->scalarNode("image_tag")->defaultValue("latest")->end()
                        ->scalarNode("storage_api_token")->end()
            ;
        $root
            ->children()
                ->variableNode("user")
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


        return $treeBuilder;
    }
}