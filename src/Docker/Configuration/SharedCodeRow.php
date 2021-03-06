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
                ->scalarNode('code_content')->isRequired()->cannotBeEmpty()->end()
            ->end();
        return $treeBuilder;
    }
}
