<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Component extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root('component');
        $definition = $root->children()->arrayNode('definition')->isRequired();
        Image::configureNode($definition);

        $root->children()
            ->integerNode('cpu_shares')->min(0)->defaultValue(1024)->end()
            ->scalarNode('memory')->defaultValue('256m')->end()
            ->scalarNode('configuration_format')
                ->defaultValue('json')
                ->validate()
                    ->ifNotInArray(['yaml', 'json'])
                    ->thenInvalid('Invalid configuration_format %s.')
                ->end()
            ->end()
            ->integerNode('process_timeout')->min(0)->defaultValue(3600)->end()
            ->booleanNode('forward_token')->defaultValue(false)->end()
            ->booleanNode('forward_token_details')->defaultValue(false)->end()
            ->booleanNode('inject_environment')->defaultValue(false)->end()
            ->booleanNode('default_bucket')->defaultValue(false)->end()
            ->variableNode('image_parameters')->defaultValue([])->end()
            ->scalarNode('network')
                ->validate()
                    ->ifNotInArray(['none', 'bridge'])
                    ->thenInvalid('Invalid network type %s.')
                ->end()
                ->defaultValue('bridge')
            ->end()
            ->scalarNode('default_bucket_stage')
                ->validate()
                    ->ifNotInArray(['in', 'out'])
                    ->thenInvalid('Invalid default_bucket_stage %s.')
                ->end()
                ->defaultValue('in')
            ->end()
            ->variableNode('vendor')->end()
            ->arrayNode('synchronous_actions')->prototype('scalar')->end()->end()
            ->arrayNode('logging')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('type')
                        ->validate()
                            ->ifNotInArray(['standard', 'gelf'])
                            ->thenInvalid('Invalid logging type %s.')
                        ->end()
                        ->defaultValue('standard')
                    ->end()
                    ->arrayNode('verbosity')
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
                    ->scalarNode('gelf_server_type')
                        ->validate()
                            ->ifNotInArray(['tcp', 'udp', 'http'])
                            ->thenInvalid('Invalid GELF server type %s.')
                        ->end()
                        ->defaultValue('tcp')
                    ->end()
                    ->booleanNode('no_application_errors')
                        ->defaultValue(false)
                    ->end()
                ->end()
            ->end()
            ->arrayNode('staging_storage')
                ->addDefaultsIfNotSet()
                ->children()
                    ->enumNode('input')
                        ->values(['local', 's3', 'none'])
                        ->defaultValue('local')
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
