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
                    ]
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
                        "connectionString" => 'aVeryLongString'
                    ]
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

    public function testRuntimeConfiguration()
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'safe' => true,
                    'image_tag' => '12.7.0',
                    'backend' => [
                        'type' => 'foo',
                    ]
                ],
            ],
        ]);

        self::assertSame([
            'type' => 'foo',
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
                        'bar' => 'baz',
                    ]
                ],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testConfigurationWithTableFiles()
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

    public function testArtifactsRunsConfigurationDoesNotAcceptsExtraKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "backend" under "container.artifacts".');

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => [
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
}
