<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Image implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("image");
        // System
        /**
         *             "definition" => array(
                         "dockerhub" => "ondrejhlavacek/docker-demo"
                     ),
                     "cpu_shares" => 2048,
                     "memory" => "128m"
         */
        $root
            ->children()
                ->arrayNode("definition")
                    ->children()
                        ->scalarNode("type")
                            ->isRequired()
                            ->validate()
                            ->ifNotInArray(array("dockerhub"))
                                ->thenInvalid("Invalid image type %s.")
                            ->end()
                        ->end()
                        ->scalarNode("uri")->isRequired()->end()
                    ->end()
                ->end()
            ->integerNode("cpu_shares")->isRequired()->min(0)->defaultValue(1024)->end()
            ->scalarNode("memory")->isRequired()->end()
            ;



        return $treeBuilder;
    }
}