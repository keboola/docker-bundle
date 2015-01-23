<?php
namespace Keboola\DockerBundle\Docker\Configuration\Input\File;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Manifest implements ConfigurationInterface
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
                ->integerNode("id")->isRequired()->end()
                ->scalarNode("name")->end()
                ->scalarNode("created")->end()
                ->booleanNode("is_public")->defaultValue(false)->end()
                ->booleanNode("is_encrypted")->defaultValue(false)->end()
                ->arrayNode("tags")->prototype("scalar")->end()->end()
                ->integerNode("max_age_days")->end()
                ->integerNode("size_bytes")->end()
            ;
    }
}