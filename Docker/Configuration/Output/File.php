<?php
namespace Keboola\DockerBundle\Docker\Configuration\Output;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class File implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("file");
        self::configureNode($root);
        return $treeBuilder;
    }

    public static function configureNode(NodeDefinition $node)
    {
        $node
            ->children()
                ->scalarNode("name")->end()
                ->scalarNode("content_type")->end()
                ->booleanNode("is_public")->defaultValue(false)->end()
                ->booleanNode("is_permanent")->defaultValue(false)->end()
                ->booleanNode("is_encrypted")->defaultValue(false)->end()
                ->booleanNode("notify")->defaultValue(false)->end()
                ->arrayNode("tags")->prototype("scalar")->end()->end()
            ;
    }
}