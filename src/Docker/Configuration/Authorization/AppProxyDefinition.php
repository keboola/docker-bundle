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
                            ->ifTrue(fn($v) => $v['auth_required'] === (count($v['auth']) === 0))
                            ->thenInvalid('"auth" value must be configured (only) when "auth_required" is true')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(function ($v) {
                    $definedProviders = array_map(fn($provider) => $provider['id'], $v['auth_providers']);
                    foreach ($v['auth_rules'] as $ruleId => $rule) {
                        $invalidRuleProviders = array_diff($rule['auth'], $definedProviders);

                        if (count($invalidRuleProviders) > 0) {
                            throw new InvalidArgumentException(sprintf(
                                'auth_rules.%s.auth contains unknown auth providers: %s',
                                $ruleId,
                                implode(', ', $invalidRuleProviders),
                            ));
                        }

                        // arrayNode in config automatically defaults to empty array but auth_rule[].auth must not be
                        // empty array, so we do manual cleanup
                        if (!$rule['auth_required']) {
                            unset($v['auth_rules'][$ruleId]['auth']);
                        }
                    }

                    return $v;
                })
            ->end()
        ;

        return $treeBuilder;
    }
}
