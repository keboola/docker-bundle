<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ContainerConfigurationTest extends TestCase
{
    public function testConfiguration(): void
    {
        (new Configuration\Container())->parse([
            "config" => [
                "storage" => [
                    "input" => [
                        "tables" => [
                            [
                                "source" => "in.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "tags" => ["tag1", "tag2"],
                                "query" => "esquery"
                            ]
                        ]
                    ],
                    "output" => [
                        'default_bucket' => 'in.c-my-bucket',
                        "tables" => [
                            [
                                "source" => "test.csv",
                                "destination" => "out.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "source" => "file",
                                "tags" => ["tag"]
                            ]
                        ]
                    ]
                ],
                "parameters" => [
                    ["var1" => "val1"],
                    ["arr1" => ["var2" => "val2"]]
                ],
                "authorization" => [
                    "oauth_api" => [
                        "id" => 1234,
                        "credentials" => [
                            "token" => "123456",
                            "params" => [
                                "key" => "val"
                            ]
                        ]
                    ],
                    'context' => 'wlm',
                ],
                "processors" => [
                    "before" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ],
                    "after" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ]
                ],
                "variables_id" => "12",
                "variables_values_id" => "21",
                "shared_code_id" => "34",
                "shared_code_row_ids" => ["345", "435"]
            ]
        ]);
        self::assertTrue(true);
    }

    public function testConfigurationWithWorkspaceConnection(): void
    {
        (new Configuration\Container())->parse([
            "config" => [
                "storage" => [
                    "input" => [
                        "tables" => [
                            [
                                "source" => "in.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "tags" => ["tag1", "tag2"],
                                "query" => "esquery"
                            ]
                        ]
                    ],
                    "output" => [
                        "tables" => [
                            [
                                "source" => "test.csv",
                                "destination" => "out.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "source" => "file",
                                "tags" => ["tag"]
                            ]
                        ]
                    ]
                ],
                "parameters" => [
                    ["var1" => "val1"],
                    ["arr1" => ["var2" => "val2"]]
                ],
                "authorization" => [
                    "workspace" => [
                        "container" => 'my-container',
                        "connectionString" => 'aVeryLongString',
                        "account" => 'test'
                    ],
                    'context' => 'wlm',
                ],
                "processors" => [
                    "before" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ],
                    "after" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ]
                ],
                "variables_id" => "12",
                "variables_values_id" => "21",
                "shared_code_id" => "34",
                "shared_code_row_ids" => ["345", "435"]
            ]
        ]);
        self::assertTrue(true);
    }

    public function testRuntimeConfiguration(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'safe' => true,
                    'image_tag' => '12.7.0',
                    'backend' => [
                        'type' => 'foo',
                        'context' => 'wml',
                    ]
                ],
            ],
        ]);

        self::assertSame([
            'type' => 'foo',
            'context' => 'wml',
        ], $config['runtime']['backend']);
    }

    public function testRuntimeBackendConfigurationHasDefaultEmptyValue(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [],
            ],
        ]);

        self::assertSame([], $config['runtime']['backend']);
    }

    public function testRuntimeBackendConfigurationDoesNotAcceptExtraKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "bar" under "container.runtime.backend"');

        (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'backend' => [
                        'type' => 'foo',
                        'context' => 'wml',
                        'bar' => 'baz',
                    ]
                ],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testConfigurationWithTableFiles(): void
    {
        (new Configuration\Container())->parse([
            "config" => [
                "storage" => [
                    "input" => [
                        "tables" => [],
                        "files" => [],
                    ],
                    "output" => [
                        "tables" => [],
                        "files" => [],
                        "table_files" => [
                            "tags" => ["tag"],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testArtifactsConfigurationDoesNotAcceptsExtraKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "backend" under "container.artifacts".');

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'backend' => [
                        'type' => 'foo',
                    ]
                ],
            ],
        ]);
    }

    public function artifactsRunsConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter - limit' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 3,
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 3,
                ],
            ],
        ];
        yield 'enabled filter - date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => '-7 days',
                ],
            ],
        ];
        yield 'enabled filter - limit + date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                    'date_since' => '-7 days',
                ],
            ],
        ];
    }

    /**
     * @dataProvider artifactsRunsConfigurationData
     */
    public function testArtifactsRunsConfiguration(
        array $runsConfiguration,
        array $expectedRunsConfiguration
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => $runsConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedRunsConfiguration, $config['artifacts']['runs']);
    }

    public function artifactsRunsConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - empty configuration' => [
            [
                'enabled' => true,
            ],
            'Invalid configuration for path "container.artifacts.runs": At least one of "date_since" or "limit" parameters must be defined.',
        ];
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.runs.enabled". Expected "bool", but got "string".',
        ];
        yield 'enabled - invalid limit value' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 'a',
                ],
            ],
            'Invalid type for path "container.artifacts.runs.filter.limit". Expected "int", but got "string".',
        ];
        yield 'enabled - invalid date_since value' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => [],
                ],
            ],
            'Invalid type for path "container.artifacts.runs.filter.date_since". Expected "scalar", but got "array".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.runs". Available options are "enabled", "filter".',
        ];
    }

    /**
     * @dataProvider artifactsRunsConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsRunsConfigurationThrowsErrorOnInvalidConfig(
        array $runsConfiguration,
        string $expecterErrorMessage
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => $runsConfiguration,
                ],
            ],
        ]);
    }

    public function artifactsSharedConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter' => [
            [
                'enabled' => true,
            ],
            [
                'enabled' => true,
            ],
        ];
    }

    /**
     * @dataProvider artifactsSharedConfigurationData
     */
    public function testArtifactsSharedConfiguration(
        array $sharedConfiguration,
        array $expectedSharedConfiguration
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'shared' => $sharedConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedSharedConfiguration, $config['artifacts']['shared']);
    }

    public function artifactsSharedConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.shared.enabled". Expected "bool", but got "string".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.shared". Available option is "enabled".',
        ];
    }

    /**
     * @dataProvider artifactsSharedConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsSharedConfigurationThrowsErrorOnInvalidConfig(
        array $sharedConfiguration,
        string $expecterErrorMessage
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'shared' => $sharedConfiguration,
                ],
            ],
        ]);
    }

    public function artifactsCustomConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter - component' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
        ];
        yield 'enabled filter - config_id' => [
            [
                'enabled' => true,
                'filter' => [
                    'config_id' => '123456',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'config_id' => '123456',
                ],
            ],
        ];
        yield 'enabled filter - branch_id' => [
            [
                'enabled' => true,
                'filter' => [
                    'branch_id' => 'main',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'branch_id' => 'main',
                ],
            ],
        ];
        yield 'enabled filter' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                ],
            ],
        ];
        yield 'enabled filter - limit' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'limit' => 123,
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'limit' => 123,
                ],
            ],
        ];
        yield 'enabled filter - date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'date_since' => '-7 days',
                ],
            ],
        ];
    }

    /**
     * @dataProvider artifactsCustomConfigurationData
     */
    public function testArtifactsCustomConfiguration(
        array $customConfiguration,
        array $expectedCustomConfiguration
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'custom' => $customConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedCustomConfiguration, $config['artifacts']['custom']);
    }

    public function artifactsCustomConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - empty configuration' => [
            [
                'enabled' => true,
            ],
            'Invalid configuration for path "container.artifacts.custom": "component_id", "config_id" and "branch_id" parameters must be defined.',
        ];
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.custom.enabled". Expected "bool", but got "string".',
        ];
        yield 'enabled - invalid limit value' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 'a',
                ],
            ],
            'Invalid type for path "container.artifacts.custom.filter.limit". Expected "int", but got "string".',
        ];
        yield 'enabled - invalid date_since value' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => [],
                ],
            ],
            'Invalid type for path "container.artifacts.custom.filter.date_since". Expected "scalar", but got "array".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.custom". Available options are "enabled", "filter".',
        ];
    }

    /**
     * @dataProvider artifactsCustomConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsCustomConfigurationThrowsErrorOnInvalidConfig(
        array $customConfiguration,
        string $expecterErrorMessage
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'custom' => $customConfiguration,
                ],
            ],
        ]);
    }

    public function testArtifactsHavingMultipleFiltersEnabled(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => [
                        'enabled' => true,
                        'filter' => [
                            'limit' => 1,
                        ],
                    ],
                    'custom' => [
                        'enabled' => true,
                        'filter' => [
                            'component_id' => 'keboola.orchestrator',
                        ],
                    ],
                    'shared' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        self::assertSame([
            'runs' => [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                ],
            ],
            'custom' => [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
            'shared' => ['enabled' => true],
        ], $config['artifacts']);
    }
}
