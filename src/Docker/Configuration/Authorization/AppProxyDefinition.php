<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration\Authorization;

use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class AppProxyDefinition implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('app_proxy');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('auth_providers')
                    ->isRequired()
                    ->arrayPrototype()
                        ->ignoreExtraKeys(false)
                        ->children()
                            ->scalarNode('id')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(fn($v) => !is_string($v))
                                    ->thenInvalid('value must be a string')
                                ->end()
                            ->end()
                            ->scalarNode('type')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(fn($v) => !is_string($v))
                                    ->thenInvalid('value must be a string')
                                ->end()
                            ->end()
                            ->arrayNode('allowed_roles')
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()
                                    ->cannotBeEmpty()
                                    ->validate()
                                        ->ifTrue(fn($v) => !is_string($v))
                                        ->thenInvalid('value must be a string')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->always(function (array $v) {
                                // arrayNode in config automatically defaults to empty array but allowed_roles must not
                                // be an empty array, so we do manual cleanup
                                if (count($v['allowed_roles']) === 0) {
                                    unset($v['allowed_roles']);
                                }

                                return $v;
                            })
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('auth_rules')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->ignoreExtraKeys(false)
                        ->children()
                            ->scalarNode('type')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(fn($v) => !is_string($v))
                                    ->thenInvalid('value must be a string')
                                ->end()
                            ->end()
                            ->booleanNode('auth_required')
                                ->isRequired()
                            ->end()
                            ->arrayNode('auth')
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()
                                    ->cannotBeEmpty()
                                    ->validate()
                                        ->ifTrue(fn($v) => !is_string($v))
                                        ->thenInvalid('value must be a string')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->always(function (array $v) {
                                // arrayNode in config automatically defaults to empty array but auth must not
                                // be an empty array, so we do manual cleanup
                                if (count($v['auth']) === 0) {
                                    unset($v['auth']);
                                }

                                return $v;
                            })
                        ->end()
                        ->validate()
                            ->ifTrue(fn($v) => $v['auth_required'] === !isset($v['auth']))
                            ->thenInvalid('"auth" value must be configured (only) when "auth_required" is true')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(function ($v) {
                    $definedProviders = array_map(fn($provider) => $provider['id'], $v['auth_providers']);
                    foreach ($v['auth_rules'] as $ruleId => $rule) {
                        $invalidRuleProviders = array_diff($rule['auth'] ?? [], $definedProviders);

                        if (count($invalidRuleProviders) > 0) {
                            throw new InvalidArgumentException(sprintf(
                                'auth_rules.%s.auth contains unknown auth providers: %s',
                                $ruleId,
                                implode(', ', $invalidRuleProviders),
                            ));
                        }
                    }

                    return $v;
                })
            ->end()
        ;

        return $treeBuilder;
    }
}
