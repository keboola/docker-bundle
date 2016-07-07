<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Monolog\Logger;
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
                            ->ifNotInArray(["dockerhub", "dockerhub-private", "dummy", "builder", "quayio", "quayio-private"])
                                ->thenInvalid("Invalid image type %s.")
                            ->end()
                        ->end()
                        ->scalarNode("uri")->isRequired()->end()
                        ->scalarNode("tag")->defaultValue("latest")->end()
                        ->arrayNode("repository")
                            ->children()
                                ->scalarNode("username")->end()
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
                                ->arrayNode("commands")
                                    ->prototype("scalar")->end()
                                ->end()
                                ->arrayNode("parameters")
                                    ->prototype("array")
                                        ->children()
                                            ->scalarNode("name")->isRequired()->end()
                                            ->booleanNode("required")->defaultValue(true)->end()
                                            ->scalarNode("type")
                                                ->isRequired()
                                                ->validate()
                                                    ->ifNotInArray(["int", "string", "argument", "plain_string", "enumeration"])
                                                    ->thenInvalid("Invalid image type %s.")
                                                ->end()
                                            ->end()
                                            ->scalarNode("default_value")->defaultValue(null)->end()
                                            ->arrayNode("values")->prototype("scalar")->end()->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->scalarNode("version")->end()
                                ->booleanNode("cache")->defaultValue(true)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->variableNode("image_parameters")->end()
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
            ->booleanNode("default_bucket")->defaultValue(false)->end()
            ->scalarNode("network")
                ->validate()
                    ->ifNotInArray(["none", "bridge"])
                    ->thenInvalid("Invalid network type %s.")
                ->end()
            ->end()
            ->scalarNode("default_bucket_stage")
                ->validate()
                    ->ifNotInArray(["in", "out"])
                    ->thenInvalid("Invalid default_bucket_stage %s.")
                ->end()
            ->end()
            ->variableNode("vendor")->end()
            ->arrayNode("synchronous_actions")->prototype("scalar")->end()->end()
            ->arrayNode("logging")
                ->children()
                    ->scalarNode("type")
                        ->validate()
                            ->ifNotInArray(["standard", "gelf"])
                            ->thenInvalid("Invalid logging type %s.")
                        ->end()
                        ->defaultValue('standard')
                    ->end()
                    ->arrayNode("verbosity")
                        ->prototype('scalar')->end()
                        ->defaultValue([
                            Logger::DEBUG => StorageApiHandler::VERBOSITY_NONE,
                            Logger::INFO => StorageApiHandler::VERBOSITY_NORMAL,
                            Logger::NOTICE => StorageApiHandler::VERBOSITY_NORMAL,
                            Logger::WARNING => StorageApiHandler::VERBOSITY_NORMAL,
                            Logger::ERROR => StorageApiHandler::VERBOSITY_NORMAL,
                            Logger::CRITICAL => StorageApiHandler::VERBOSITY_CAMOUFLAGE,
                            Logger::ALERT => StorageApiHandler::VERBOSITY_CAMOUFLAGE,
                            Logger::EMERGENCY => StorageApiHandler::VERBOSITY_CAMOUFLAGE,
                        ])
                    ->end()
                    ->scalarNode("gelf_server_type")
                        ->validate()
                            ->ifNotInArray(["tcp", "udp", "http"])
                            ->thenInvalid("Invalid GELF server type %s.")
                        ->end()
                        ->defaultValue("tcp")
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
