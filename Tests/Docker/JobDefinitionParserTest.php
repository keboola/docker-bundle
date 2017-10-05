<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\Syrup\Exception\UserException;

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
        $this->assertNull($parser->getJobDefinitions()[0]->getRowVersion());
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
        $this->assertNull($parser->getJobDefinitions()[0]->getRowVersion());
        $this->assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        $this->assertEquals($config['state'], $parser->getJobDefinitions()[0]->getState());
    }

    public function testMultiRowConfiguration()
    {
    }
}
