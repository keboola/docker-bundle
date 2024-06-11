<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Component extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('component');
        $root = $treeBuilder->getRootNode();
        $data = $root->ignoreExtraKeys(false)->children()->arrayNode('data')->isRequired();

        $definition = $data->children()->arrayNode('definition')->isRequired();
        Image::configureNode($definition);

        $data->children()
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
            ->booleanNode('default_bucket')->defaultValue(false)->end()
            ->variableNode('image_parameters')->defaultValue([])->end()
            ->scalarNode('network')
                ->validate()
                    ->ifNotInArray(['none', 'bridge', 'no-internet'])
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
                            Logger::DEBUG => StorageApiHandlerInterface::VERBOSITY_NONE,
                            Logger::INFO => StorageApiHandlerInterface::VERBOSITY_NORMAL,
                            Logger::NOTICE => StorageApiHandlerInterface::VERBOSITY_NORMAL,
                            Logger::WARNING => StorageApiHandlerInterface::VERBOSITY_NORMAL,
                            Logger::ERROR => StorageApiHandlerInterface::VERBOSITY_NORMAL,
                            Logger::CRITICAL => StorageApiHandlerInterface::VERBOSITY_CAMOUFLAGE,
                            Logger::ALERT => StorageApiHandlerInterface::VERBOSITY_CAMOUFLAGE,
                            Logger::EMERGENCY => StorageApiHandlerInterface::VERBOSITY_CAMOUFLAGE,
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
                        ->values(['local', 's3', 'abs', 'none', 'workspace-snowflake', 'workspace-redshift',
                            'workspace-synapse', 'workspace-abs', 'workspace-exasol', 'workspace-teradata',
                            'workspace-bigquery',
                        ])
                        ->defaultValue('local')
                    ->end()
                    ->enumNode('output')
                        ->values(['local', 'none', 'workspace-snowflake', 'workspace-redshift',
                            'workspace-synapse', 'workspace-abs', 'workspace-exasol', 'workspace-teradata',
                            'workspace-bigquery',
                        ])
                        ->defaultValue('local')
                    ->end()
                ->end()
            ->end()
        ->end();
        return $treeBuilder;
    }
}
