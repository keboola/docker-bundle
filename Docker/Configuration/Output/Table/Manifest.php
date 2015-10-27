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
                // leave here for backward compatibility
                ->arrayNode("primary_key")->prototype("scalar")->end()->end()
                ->scalarNode("delete_where_column")->end()
                ->arrayNode("delete_where_values")->prototype("scalar")->end()->end()
                ->scalarNode("delete_where_operator")
                    ->defaultValue("eq")
                    ->validate()
                    ->ifNotInArray(array("eq", "ne"))
                        ->thenInvalid("Invalid operator in delete_where_operator %s.")
                ->end()

            ;
    }
}
