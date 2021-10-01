<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Keboola\DockerBundle\Docker\Configuration;

class SharedCodeRow extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder;
        $rootNode = $treeBuilder->root('configuration');
        $rootNode
            ->children()
                ->scalarNode('variables_id')->end()
                ->variableNode('code_content')
                    ->validate()
                        ->ifTrue(function ($v) {
                            return (false === is_string($v) && false === is_array($v)) || empty($v);
                        })
                        ->thenInvalid('code_content must be either string or array and cannot be empty')
                    ->end()
                ->end()
            ->end();
        return $treeBuilder;
    }
}
