<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinitionParser;

class JobDefinitionParserTest extends \PHPUnit_Framework_TestCase
{
    private function getComponent()
    {
        return new Component(
            [
                'id' => 'keboola.r-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'dockerhub',
                        'uri' => 'keboola/docker-demo',
                    ],
                ],
            ]
        );
    }

    public function testSimpleConfigData()
    {
        $configData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    'tdata <- t(data[, !(names(data) %in% ("name"))])',
                    'colnames(tdata) <- data[["name"]]',
                    'tdata <- data.frame(column = rownames(tdata), tdata)',
                    'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                ],
            ],
        ];

        $expected = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                        ],
                    ],
                    'files' => [],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                            'incremental' => false,
                            'primary_key' => [],
                            'columns' => [],
                            'delete_where_values' => [],
                            'delete_where_operator' => 'eq',
                            'delimiter' => ',',
                            'enclosure' => '"',
                            'metadata' => [],
                            'column_metadata' => [],
                        ],
                    ],
                    'files' => [],
                ],
            ],
            'parameters' =>
                [
                    'script' =>
                        [
                            0 => 'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            1 => 'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            2 => 'colnames(tdata) <- data[["name"]]',
                            3 => 'tdata <- data.frame(column = rownames(tdata), tdata)',
                            4 => 'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                        ],
                ],
            'processors' => [],
        ];

        $parser = new JobDefinitionParser();
        $parser->parseConfigData($this->getComponent(), $configData);

        $this->assertCount(1, $parser->getJobDefinitions());
        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        $this->assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
        $this->assertNull($parser->getJobDefinitions()[0]->getConfigId());
        $this->assertNull($parser->getJobDefinitions()[0]->getConfigVersion());
        $this->assertNull($parser->getJobDefinitions()[0]->getRowId());
        $this->assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        $this->assertEmpty($parser->getJobDefinitions()[0]->getState());
    }

    public function testSingleRowConfiguration()
    {
        $config = [
            'id' => 'my-config',
            'version' => 1,
            'configuration' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'script' => [
                        'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                        'tdata <- t(data[, !(names(data) %in% ("name"))])',
                        'colnames(tdata) <- data[["name"]]',
                        'tdata <- data.frame(column = rownames(tdata), tdata)',
                        'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                    ],
                ],
            ],
            'state' => ['key' => 'val'],
            'rows' => [],
        ];

        $expected = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                        ],
                    ],
                    'files' => [],
                ],
                'output' =>
                    [
                        'tables' =>
                            [
                                [
                                    'source' => 'transpose.csv',
                                    'destination' => 'out.c-docker-test.transposed',
                                    'incremental' => false,
                                    'primary_key' => [],
                                    'columns' => [],
                                    'delete_where_values' => [],
                                    'delete_where_operator' => 'eq',
                                    'delimiter' => ',',
                                    'enclosure' => '"',
                                    'metadata' => [],
                                    'column_metadata' => [],
                                ],
                            ],
                        'files' => [],
                    ],
            ],
            'parameters' =>
                [
                    'script' =>
                        [
                            0 => 'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            1 => 'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            2 => 'colnames(tdata) <- data[["name"]]',
                            3 => 'tdata <- data.frame(column = rownames(tdata), tdata)',
                            4 => 'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                        ],
                ],
            'processors' => [],
        ];
        
        $parser = new JobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config);

        $this->assertCount(1, $parser->getJobDefinitions());
        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        $this->assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
        $this->assertEquals('my-config', $parser->getJobDefinitions()[0]->getConfigId());
        $this->assertEquals(1, $parser->getJobDefinitions()[0]->getConfigVersion());
        $this->assertNull($parser->getJobDefinitions()[0]->getRowId());
        $this->assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        $this->assertEquals($config['state'], $parser->getJobDefinitions()[0]->getState());
    }

    public function testMultiRowConfiguration()
    {
        $config = [
            'id' => 'my-config',
            'version' => 3,
            'configuration' => [
                'parameters' => [
                    'credentials' => [
                        'username' => 'user',
                        '#password' => 'password'
                    ],
                ],
            ],
            'state' => ['key' => 'val'],
            'rows' => [
                [
                    'id' => 'row1',
                    'version' => 2,
                    'isDisabled' => true,
                    'configuration' => [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-docker-test.source',
                                        'destination' => 'transpose.csv',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'credentials' => [
                                'username' => 'override user',
                            ],
                            'key' => 'val'
                        ]
                    ],
                    'state' => [
                        'key1' => 'val1'
                    ]
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'configuration' => [
                        'storage' => [
                            'input' => []
                        ]
                    ],
                    'state' => [
                        'key2' => 'val2'
                    ]

                ],
            ],
        ];

        $expectedRow1 = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                        ]
                    ],
                    'files' => []
                ],
            ],
            'parameters' => [
                'credentials' => [
                    'username' => 'override user',
                    '#password' => 'password'
                ],
                'key' => 'val'
            ],
            'processors' => [],
        ];

        $expectedRow2 = [
            'storage' => [
                'input' => [
                    'tables' => [],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'credentials' => [
                    'username' => 'user',
                    '#password' => 'password'
                ],
            ],
            'processors' => [],
        ];

        $parser = new JobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config);

        $this->assertCount(2, $parser->getJobDefinitions());
        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        $this->assertEquals($expectedRow1, $parser->getJobDefinitions()[0]->getConfiguration());
        $this->assertEquals('my-config', $parser->getJobDefinitions()[0]->getConfigId());
        $this->assertEquals(3, $parser->getJobDefinitions()[0]->getConfigVersion());
        $this->assertEquals('row1', $parser->getJobDefinitions()[0]->getRowId());
        $this->assertTrue($parser->getJobDefinitions()[0]->isDisabled());
        $this->assertEquals(['key1' => 'val1'], $parser->getJobDefinitions()[0]->getState());

        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[1]->getComponentId());
        $this->assertEquals($expectedRow2, $parser->getJobDefinitions()[1]->getConfiguration());
        $this->assertEquals('my-config', $parser->getJobDefinitions()[1]->getConfigId());
        $this->assertEquals(3, $parser->getJobDefinitions()[1]->getConfigVersion());
        $this->assertEquals('row2', $parser->getJobDefinitions()[1]->getRowId());
        $this->assertFalse($parser->getJobDefinitions()[1]->isDisabled());
        $this->assertEquals(['key2' => 'val2'], $parser->getJobDefinitions()[1]->getState());
    }
}
