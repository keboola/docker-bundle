<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\Syrup\Exception\UserException;

class JobDefinitionParserTest extends \PHPUnit_Framework_TestCase
{

    private function getComponents()
    {
        return [
            [
                'id' => 'keboola.r-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'dockerhub',
                        'uri' => 'keboola/docker-demo'
                    ]
                ]
            ]
        ];
    }

    public function testSimpleConfigData()
    {
        $configData = [
            'storage' => [
                'input' => [
                    'tables' => [[
                        'source' => 'in.c-docker-test.source',
                        'destination' => 'transpose.csv'
                    ]]
                ],
                'output' => [
                    'tables' => [[
                        'source' => 'transpose.csv',
                        'destination' => 'out.c-docker-test.transposed'
                    ]]
                ]
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    'tdata <- t(data[, !(names(data) %in% ("name"))])',
                    'colnames(tdata) <- data[["name"]]',
                    'tdata <- data.frame(column = rownames(tdata), tdata)',
                    'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                ]
            ]
        ];
        $parser = new JobDefinitionParser($this->getComponents());
        $parser->parseConfigData('keboola.r-transformation', $configData);

        $this->assertCount(1, $parser->getJobDefinitions());
        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        $this->assertEquals($configData, $parser->getJobDefinitions()[0]->getConfiguration());
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
                        'tables' => [[
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv'
                        ]]
                    ],
                    'output' => [
                        'tables' => [[
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed'
                        ]]
                    ]
                ],
                'parameters' => [
                    'script' => [
                        'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                        'tdata <- t(data[, !(names(data) %in% ("name"))])',
                        'colnames(tdata) <- data[["name"]]',
                        'tdata <- data.frame(column = rownames(tdata), tdata)',
                        'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                    ]
                ]
            ],
            'state' => ['key' => 'val'],
            'rows' => []
        ];
        $parser = new JobDefinitionParser($this->getComponents());
        $parser->parseConfig('keboola.r-transformation', $config);

        $this->assertCount(1, $parser->getJobDefinitions());
        $this->assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        $this->assertEquals($config['configuration'], $parser->getJobDefinitions()[0]->getConfiguration());
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

    public function testComponentDoesNotExist()
    {
        $parser = new JobDefinitionParser($this->getComponents());
        try {
            $parser->parseConfigData('keboola.my-nonexisting-component', []);
            $this->fail("Exception not caught.");
        } catch (UserException $e) {
            $this->assertEquals('Component \'keboola.my-nonexisting-component\' not found.', $e->getMessage());
        }
    }
}
