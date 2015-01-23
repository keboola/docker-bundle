<?php
namespace Keboola\DockerBundle\Docker\Configuration\Input;

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
                ->arrayNode("tags")
                    ->prototype("scalar")->end()
                ->end()
                ->scalarNode("query")->end()
            ->end()
        ->validate()
            ->ifTrue(function($v) {
                if ((!isset($v["tags"]) || count($v["tags"] == 0)) && !isset($v["query"])) {
                    return true;
                }
                return false;
            })
                ->thenInvalid("At least one of 'tags' or 'query' parameters must be defined.");
        ;
    }
}