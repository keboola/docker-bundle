<?php
namespace Keboola\DockerBundle\Docker\Configuration\Output\Table;

use Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Manifest extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root("table");
        self::configureNode($root);
        return $treeBuilder;
    }

    public static function configureNode(NodeDefinition $node)
    {
        $node
            ->children()
                ->scalarNode("destination")->isRequired()->end()
                ->booleanNode("incremental")->defaultValue(false)->end()
                ->arrayNode("primary_key")->prototype("scalar")->end()->end()
                ->scalarNode("delete_where_column")->end()
                ->arrayNode("delete_where_values")->prototype("scalar")->end()->end()
                ->scalarNode("delete_where_operator")
                    ->defaultValue("eq")
                    ->validate()
                    ->ifNotInArray(array("eq", "ne"))
                        ->thenInvalid("Invalid operator in delete_where_operator %s.")
                    ->end()
                ->end()
                ->scalarNode("delimiter")->defaultValue(",")->end()
                ->scalarNode("enclosure")->defaultValue("\"")->end()
                ->scalarNode("escaped_by")->defaultValue("")->end() //TODO: escaped_by is deprecated and should not be used any more
            ;
    }
}
