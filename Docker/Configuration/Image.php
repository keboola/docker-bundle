<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Image extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root('image');
        self::configureNode($root);
        return $treeBuilder;
    }

    public static function configureNode(NodeDefinition $node)
    {
        $node->children()
            ->scalarNode('type')
                ->isRequired()
                ->validate()
                    ->ifNotInArray(['dockerhub', 'dockerhub-private', 'builder', 'quayio', 'quayio-private', 'aws-ecr'])
                        ->thenInvalid('Invalid image type %s.')
                    ->end()
                ->end()
            ->scalarNode('uri')->isRequired()->end()
            ->scalarNode('tag')->defaultValue('latest')->end()
            ->arrayNode('repository')
                ->children()
                    ->scalarNode('region')->end()
                    ->scalarNode('username')->end()
                    ->scalarNode('#password')->end()
                    ->scalarNode('server')->end()
                ->end()
            ->end()
            ->arrayNode('build_options')
                ->children()
                    ->scalarNode('parent_type')
                        ->defaultValue('legacy')
                        ->validate()
                            ->ifNotInArray(['dockerhub', 'dockerhub-private', 'builder', 'quayio', 'quayio-private', 'aws-ecr', 'legacy'])
                            ->thenInvalid('Invalid image type %s.')
                            ->end()
                        ->end()
                    ->arrayNode('repository')
                        ->isRequired()
                        ->children()
                            ->scalarNode('uri')->isRequired()->end()
                            ->scalarNode('type')
                                ->isRequired()
                                ->validate()
                                    ->ifNotInArray(['git'])
                                    ->thenInvalid('Invalid repository_type %s.')
                                ->end()
                            ->end()
                            ->scalarNode('username')->end()
                            ->scalarNode('#password')->end()
                        ->end()
                    ->end()
                    ->scalarNode('entry_point')->isRequired()->end()
                    ->arrayNode('commands')
                        ->prototype('scalar')->end()
                    ->end()
                    ->arrayNode('parameters')
                        ->prototype('array')
                            ->children()
                                ->scalarNode('name')->isRequired()->end()
                                ->booleanNode('required')->defaultValue(true)->end()
                                ->scalarNode('type')
                                    ->isRequired()
                                    ->validate()
                                        ->ifNotInArray(['int', 'string', 'argument', 'plain_string', 'enumeration'])
                                        ->thenInvalid('Invalid image type %s.')
                                    ->end()
                                ->end()
                                ->scalarNode('default_value')->defaultValue(null)->end()
                                ->arrayNode('values')->prototype('scalar')->end()->end()
                            ->end()
                        ->end()
                    ->end()
                    ->scalarNode('version')->end()
                    ->booleanNode('cache')->defaultValue(true)->end()
                ->end()
            ->end()
        ->end();
    }
}
