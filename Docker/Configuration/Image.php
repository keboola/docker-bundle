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
                    ->isRequired()
                    ->children()
                        ->scalarNode("type")
                            ->isRequired()
                            ->validate()
                            ->ifNotInArray(["dockerhub", "dockerhub-private", "dummy", "builder"])
                                ->thenInvalid("Invalid image type %s.")
                            ->end()
                        ->end()
                        ->scalarNode("uri")->isRequired()->end()
                        ->arrayNode("repository")
                            ->children()
                                ->scalarNode("email")->end()
                                ->scalarNode("username")->end()
                                ->scalarNode("password")->end()
                                ->scalarNode("#password")->end()
                                ->scalarNode("server")->end()
                            ->end()
                        ->end()
                        ->arrayNode("build_options")
                            ->children()
                                ->arrayNode("repository")
                                    ->isRequired()
                                    ->children()
                                        ->scalarNode("uri")->isRequired()->end()
                                        ->scalarNode("type")
                                            ->isRequired()
                                            ->validate()
                                                ->ifNotInArray(["git"])
                                                ->thenInvalid("Invalid repository_type %s.")
                                            ->end()
                                        ->end()
                                        ->scalarNode("username")->end()
                                        ->scalarNode("#password")->end()
                                    ->end()
                                ->end()
                                ->scalarNode("entry_point")->isRequired()->end()
                                ->variableNode("commands")->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->integerNode("cpu_shares")->min(0)->defaultValue(1024)->end()
            ->scalarNode("memory")->defaultValue("64m")->end()
            ->scalarNode("configuration_format")
                ->defaultValue("yaml")
                ->validate()
                ->ifNotInArray(["yaml", "json"])
                    ->thenInvalid("Invalid configuration_format %s.")
                ->end()
            ->end()
            ->integerNode("process_timeout")->min(0)->defaultValue(3600)->end()
            ->booleanNode("forward_token")->defaultValue(false)->end()
            ->booleanNode("forward_token_details")->defaultValue(false)->end()
            ->booleanNode("streaming_logs")->defaultValue(true)->end()
            ->variableNode("vendor")->end()
        ;

        return $treeBuilder;
    }
}
