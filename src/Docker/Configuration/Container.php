<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\InputMapping\Configuration\File as InputFile;
use Keboola\InputMapping\Configuration\Table as InputTable;
use Keboola\OutputMapping\Configuration\File as OutputFile;
use Keboola\OutputMapping\Configuration\Table as OutputTable;
use Keboola\OutputMapping\Configuration\TableFile as OutputTableFile;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Container extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('container');
        $root = $treeBuilder->getRootNode();
        // System
        $root
            ->children()
                ->variableNode("parameters")->end()
                ->arrayNode("runtime")
                    ->ignoreExtraKeys(false)
                    ->children()
                        ->booleanNode('safe')->defaultNull()->end()
                        ->scalarNode('image_tag')->defaultNull()->end()
                        ->arrayNode('backend')
                            ->treatNullLike([])
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('type')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode("variables_id")->end()
                ->scalarNode("variables_values_id")->end()
                ->scalarNode("shared_code_id")->end()
                ->arrayNode("shared_code_row_ids")
                    ->prototype("scalar")->end()
                ->end()
                ->variableNode("image_parameters")->end()
        ;
        $storage = $root
            ->children()
                ->arrayNode("storage");

        $input = $storage
            ->children()
                ->arrayNode("input");

        $inputTable = $input
            ->children()
                ->arrayNode("tables")
                    ->prototype("array")
        ;
        InputTable::configureNode($inputTable);

        $inputFile = $input
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        InputFile::configureNode($inputFile);

        $output = $storage
            ->children()
                ->arrayNode("output");

        $outputTable = $output
            ->children()
                ->scalarNode('default_bucket')->end()
                ->arrayNode('tables')
                    ->prototype('array')
        ;
        OutputTable::configureNode($outputTable);

        $outputFile = $output
            ->children()
                ->arrayNode("files")
                    ->prototype("array")
        ;
        OutputFile::configureNode($outputFile);

        $outputTableFile = $output
            ->children()
                ->arrayNode('table_files')
        ;
        OutputTableFile::configureNode($outputTableFile);

        // authorization
        $root->children()
            ->arrayNode("authorization")
            ->children()
                ->arrayNode("oauth_api")
                    ->children()
                        ->scalarNode("id")->end()
                        ->scalarNode("version")->defaultValue(2)->end()
                        ->variableNode("credentials")->end()
                    ->end()
                ->end()
                ->arrayNode("workspace")
                    ->ignoreExtraKeys(false)
                ->end()
            ->end()
        ->end();

        // action
        $root->children()->scalarNode("action")->end();

        // processors
        $root->children()
            ->arrayNode('processors')
                ->children()
                    ->arrayNode('before')
                        ->prototype('array')
                            ->children()
                                ->variableNode('parameters')->end()
                                ->arrayNode('definition')
                                    ->children()
                                        ->scalarNode('component')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->arrayNode('after')
                        ->prototype('array')
                            ->children()
                                ->variableNode('parameters')->end()
                                ->arrayNode('definition')
                                    ->children()
                                        ->scalarNode('component')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        // artifacts
        $root->children()
            ->arrayNode('artifacts')
            ->children()
                ->arrayNode('options')
                    ->children()
                        ->booleanNode('zip')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('runs')
                    ->validate()
                        ->ifTrue(function ($v) {
                            if ($v['enabled'] === true) {
                                if (!isset($v['filter']['date_since']) && !isset($v['filter']['limit'])) {
                                    return true;
                                }
                            }
                            return false;
                        })
                        ->thenInvalid('At least one of "date_since" or "limit" parameters must be defined.')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('filter')
                            ->children()
                                ->scalarNode('date_since')->end()
                                ->integerNode('limit')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('custom')
                    ->validate()
                        ->ifTrue(function ($v) {
                            if ($v['enabled'] === true) {
                                if (!isset($v['filter']['component_id']) && !isset($v['filter']['config_id']) && !isset($v['filter']['branch_id'])) {
                                    return true;
                                }
                            }
                            return false;
                        })
                        ->thenInvalid('"component_id", "config_id" and "branch_id" parameters must be defined.')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('filter')
                            ->children()
                                ->scalarNode('component_id')->end()
                                ->scalarNode('config_id')->end()
                                ->scalarNode('branch_id')->end()
                                ->scalarNode('date_since')->end()
                                ->integerNode('limit')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('shared')
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
        ->end();
        return $treeBuilder;
    }
}
