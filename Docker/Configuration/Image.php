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
                            ->ifNotInArray(array("dockerhub", "dockerhub-private"))
                                ->thenInvalid("Invalid image type %s.")
                            ->end()
                        ->end()
                        ->scalarNode("uri")->isRequired()->end()
                        ->variableNode("repository")->end()
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
            ->integerNode("process_timeout")->min(0)->defaultValue(3600)->end()
            ->booleanNode("forward_token")->defaultValue(false)->end()
            ->booleanNode("forward_token_details")->defaultValue(false)->end()
            ->booleanNode("streaming_logs")->defaultValue(true)->end()
        ;

        return $treeBuilder;
    }
}
