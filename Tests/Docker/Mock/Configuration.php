<?php
namespace Keboola\DockerBundle\Tests\Docker\Mock;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Configuration extends \Keboola\DockerBundle\Docker\Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $treeBuilder->root("test")
            ->children()
            ->variableNode("parameters")->end()
            ->variableNode("storage")->end();
        return $treeBuilder;
    }
}
