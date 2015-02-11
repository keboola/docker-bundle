<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Image extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("image");

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
            ->scalarNode("configuration_format")
                ->defaultValue("yaml")
                ->validate()
                ->ifNotInArray(array("yaml", "json"))
                    ->thenInvalid("Invalid configuration_format %s.")
                ->end()
            ->end()

            ;

        return $treeBuilder;
    }
}
