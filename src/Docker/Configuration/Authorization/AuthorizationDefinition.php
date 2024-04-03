<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration\Authorization;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class AuthorizationDefinition implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('authorization');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('oauth_api')
                    ->children()
                        ->scalarNode('id')->end()
                        ->scalarNode('version')->end()
                        ->variableNode('credentials')->end()
                    ->end()
                ->end()
                ->arrayNode('workspace')
                    ->children()
                        ->scalarNode('host')->end()
                        ->scalarNode('account')->end()
                        ->scalarNode('warehouse')->end()
                        ->scalarNode('database')->end()
                        ->scalarNode('schema')->end()
                        ->scalarNode('region')->end()
                        ->scalarNode('user')->end()
                        ->scalarNode('password')->end()
                        ->scalarNode('container')->end()
                        ->scalarNode('connectionString')->end()
                        ->variableNode('credentials')->end()
                    ->end()
                ->end()
                ->scalarNode('context')->end()
                ->append((new AppProxyDefinition())->getConfigTreeBuilder()->getRootNode())
            ->end()
        ;

        return $treeBuilder;
    }
}
