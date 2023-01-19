<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\ImageFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Image extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('image');
        $root = $treeBuilder->getRootNode();
        self::configureNode($root);
        return $treeBuilder;
    }

    public static function configureNode(NodeDefinition $node)
    {
        /** @var ArrayNodeDefinition $node */
        $node
            ->children()
            ->scalarNode('type')
                ->isRequired()
                ->validate()
                    ->ifNotInArray(ImageFactory::KNOWN_IMAGE_TYPES)
                        ->thenInvalid('Invalid image type %s.')
                    ->end()
                ->end()
            ->scalarNode('uri')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('tag')->defaultValue('latest')->end()
            ->scalarNode('digest')->defaultValue('')->end()
            ->arrayNode('repository')
                ->children()
                    ->scalarNode('region')->end()
                    ->scalarNode('username')->end()
                    ->scalarNode('#password')->end()
                    ->scalarNode('server')->end()
                ->end()
            ->end()
        ->end();
    }
}
