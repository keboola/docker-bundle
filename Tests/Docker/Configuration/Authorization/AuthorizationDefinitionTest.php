<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration\Authorization;

use Keboola\DockerBundle\Docker\Configuration\Authorization\AuthorizationDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class AuthorizationDefinitionTest extends TestCase
{
    public static function provideValidAuthorizationConfig(): iterable
    {
        yield 'no auth' => [
            'config' => [],
        ];

        yield 'configured oauth' => [
            'config' => [
                'oauth_api' => [
                    'id' => '123',
                    'version' => 2,
                    'credentials' => [
                        'key' => 'value',
                    ],
                ],
            ],
        ];

        yield 'configured workspace' => [
            'config' => [
                'workspace' => [
                    'account' => 'test',
                    'region' => 'mordor',
                    'credentials' => [
                        'client_id' => 'client123',
                        'private_key' => 'very-secret-private-key',
                    ],
                ],
            ],
        ];

        yield 'configured workspace with privateKey' => [
            'config' => [
                'workspace' => [
                    'host' => 'host-value',
                    'account' => 'account-value',
                    'warehouse' => 'warehouse-value',
                    'database' => 'database-value',
                    'schema' => 'schema-value',
                    'region' => 'region-value',
                    'privateKey' => 'private-key-value',
                ],
            ],
        ];

        yield 'configured context' => [
            'config' => [
                'context' => 'wlm',
            ],
        ];

        yield 'configured app proxy' => [
            'config' => [
                'app_proxy' => [
                    'auth_providers' => [],
                    'auth_rules' => [
                        [
                            'type' => 'pathPrefix',
                            'value' => '/',
                            'auth_required' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @dataProvider provideValidAuthorizationConfig */
    public function testValidAuthorizationConfig(?array $config): void
    {
        (new Processor())->processConfiguration(new AuthorizationDefinition(), [
            'authorization' => $config,
        ]);
        self::assertTrue(true);
    }
}
