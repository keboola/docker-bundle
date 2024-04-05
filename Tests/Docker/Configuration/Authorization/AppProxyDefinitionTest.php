<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration\Authorization;

use Keboola\DockerBundle\Docker\Configuration\Authorization\AppProxyDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

// phpcs:disable Generic.Files.LineLength.MaxExceeded -- so that we don't have to wrap error messages
class AppProxyDefinitionTest extends TestCase
{
    public static function provideValidAppProxyAuthorizationConfig(): iterable
    {
        yield 'no auth' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
        ];

        yield 'configured auth' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => 'simple',
                    ],
                    [
                        'id' => 'aad',
                        'type' => 'oidc',
                        'client_id' => 'foo',
                        '#client_secret' => 'bar',
                        'issuer_url' => 'https://example.com',
                        'allowed_roles' => ['admin', 'guest'],
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/api',
                        'auth_required' => true,
                        'auth' => ['simple'],
                    ],
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => true,
                        'auth' => ['simple', 'aad'],
                    ],
                ],
            ],
        ];
    }

    /** @dataProvider provideValidAppProxyAuthorizationConfig */
    public function testValidAppProxyAuthorizationConfig(?array $config): void
    {
        // process the user-provided config
        $producedConfig = (new Processor())->processConfiguration(new AppProxyDefinition(), [
            'app_proxy' => $config,
        ]);

        // process the config again to check that processing produces valid config
        (new Processor())->processConfiguration(new AppProxyDefinition(), [
            'app_proxy' => $producedConfig,
        ]);

        self::assertTrue(true);
    }

    public static function provideInvalidAppProxyAuthorizationConfig(): iterable
    {
        yield 'empty config' => [
            'config' => [],
            'error' => 'The child config "auth_providers" under "app_proxy" must be configured.',
        ];

        yield 'no auth rules' => [
            'config' => [
                'auth_providers' => [],
            ],
            'error' => 'The child config "auth_rules" under "app_proxy" must be configured.',
        ];

        yield 'no auth rule' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [],
            ],
            'error' => 'The path "app_proxy.auth_rules" should have at least 1 element(s) defined.',
        ];

        yield 'invalid auth rule (no type)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The child config "type" under "app_proxy.auth_rules.0" must be configured.',
        ];

        yield 'invalid auth rule (invalid type)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 1,
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_rules.0.type": value must be a string',
        ];

        yield 'invalid auth rule (empty type)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => '',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The path "app_proxy.auth_rules.0.type" cannot contain an empty value, but got "".',
        ];

        yield 'invalid auth rule (no auth_required)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                    ],
                ],
            ],
            'error' => 'The child config "auth_required" under "app_proxy.auth_rules.0" must be configured.',
        ];

        yield 'invalid auth rule (invalid auth_required)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => 'x',
                    ],
                ],
            ],
            'error' => 'Invalid type for path "app_proxy.auth_rules.0.auth_required". Expected "bool", but got "string".',
        ];

        yield 'invalid auth rule (no auth when auth_required: true)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => true,
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_rules.0": "auth" value must be configured (only) when "auth_required" is true',
        ];

        yield 'invalid auth rule (empty auth when auth_required: true)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => true,
                        'auth' => [],
                    ],
                ],
            ],
            'error' => 'The path "app_proxy.auth_rules.0.auth" should have at least 1 element(s) defined.',
        ];

        yield 'invalid auth rule (auth set when auth_required: false)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                        'auth' => ['foo'],
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_rules.0": "auth" value must be configured (only) when "auth_required" is true',
        ];

        yield 'invalid auth rule (unknown auth provider)' => [
            'config' => [
                'auth_providers' => [],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => true,
                        'auth' => ['foo'],
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy": auth_rules.0.auth contains unknown auth providers: foo',
        ];

        yield 'invalid auth provider (no id)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'type' => 'simple',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The child config "id" under "app_proxy.auth_providers.0" must be configured.',
        ];

        yield 'invalid auth provider (invalid id)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 1,
                        'type' => 'simple',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_providers.0.id": value must be a string',
        ];

        yield 'invalid auth provider (empty id)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => '',
                        'type' => 'simple',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The path "app_proxy.auth_providers.0.id" cannot contain an empty value, but got "".',
        ];

        yield 'invalid auth provider (no type)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The child config "type" under "app_proxy.auth_providers.0" must be configured.',
        ];

        yield 'invalid auth provider (invalid type)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => 1,
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_providers.0.type": value must be a string',
        ];

        yield 'invalid auth provider (empty type)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => '',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The path "app_proxy.auth_providers.0.type" cannot contain an empty value, but got "".',
        ];

        yield 'invalid auth provider (invalid allowed_roles)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => 'simple',
                        'allowed_roles' => 'foo',
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'Invalid type for path "app_proxy.auth_providers.0.allowed_roles". Expected "array", but got "string"',
        ];

        yield 'invalid auth provider (empty allowed_roles)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => 'simple',
                        'allowed_roles' => [],
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'The path "app_proxy.auth_providers.0.allowed_roles" should have at least 1 element(s) defined.',
        ];

        yield 'invalid auth provider (invalid allowed_roles item)' => [
            'config' => [
                'auth_providers' => [
                    [
                        'id' => 'simple',
                        'type' => 'simple',
                        'allowed_roles' => [1],
                    ],
                ],
                'auth_rules' => [
                    [
                        'type' => 'pathPrefix',
                        'value' => '/',
                        'auth_required' => false,
                    ],
                ],
            ],
            'error' => 'Invalid configuration for path "app_proxy.auth_providers.0.allowed_roles.0": value must be a string',
        ];
    }

    /** @dataProvider provideInvalidAppProxyAuthorizationConfig */
    public function testInvalidAppProxyAuthorizationConfig(array $config, string $error): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($error);

        (new Processor())->processConfiguration(new AppProxyDefinition(), [
            'app_proxy' => $config,
        ]);
    }

    public function testEmptyAuthIsNotPresentInAuthRule(): void
    {
        $config = [
            'auth_providers' => [],
            'auth_rules' => [
                [
                    'type' => 'pathPrefix',
                    'value' => '/',
                    'auth_required' => false,
                ],
            ],
        ];

        $result = (new Processor())->processConfiguration(new AppProxyDefinition(), [
            'app_proxy' => $config,
        ]);

        self::assertArrayNotHasKey('auth', $result['auth_rules'][0]);
    }
}
