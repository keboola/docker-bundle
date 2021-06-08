<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ContainerConfigurationTest extends TestCase
{
    public function testConfiguration()
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
    }

    public function testConfigurationWithWorkspaceConnection()
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

    public function testRuntimeBackendConfigurationHasDefaultEmptyValue()
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [],
            ],
        ]);

        self::assertSame([], $config['runtime']['backend']);
    }

    public function testRuntimeBackendConfigurationDoesNotAcceptExtraKeys()
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
    }
}
