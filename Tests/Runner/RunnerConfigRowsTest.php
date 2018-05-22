<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;

class RunnerConfigRowsTest extends BaseRunnerTest
{
    private function clearBuckets()
    {
        foreach (['in.c-docker-test', 'out.c-docker-test'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearBuckets();

        // Create buckets
        $this->getClient()->createBucket('docker-test', Client::STAGE_IN, 'Docker TestSuite');
        $this->getClient()->createBucket('docker-test', Client::STAGE_OUT, 'Docker TestSuite');

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['docker-bundle-test']);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
        $component = new Components($this->getClient());
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    public function tearDown()
    {
        $this->clearBuckets();
        $component = new Components($this->getClient());
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        parent::tearDown();
    }

    /**
     * Transform metadata into a key-value array
     *
     * @param array $metadata
     * @return array
     */
    private function getMetadataValues($metadata)
    {
        $result = [];
        foreach ($metadata as $item) {
            $result[$item['provider']][$item['key']] = $item['value'];
        }
        return $result;
    }

    private function getComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'mkdir /data/out/tables/mytable.csv.gz && '
                            . 'touch /data/out/tables/mytable.csv.gz/part1 && '
                            . 'echo "value1" > /data/out/tables/mytable.csv.gz/part1 && '
                            . 'touch /data/out/tables/mytable.csv.gz/part2 && '
                            . 'echo "value2" > /data/out/tables/mytable.csv.gz/part2'
                    ],
                ],
            ],
        ]);
    }

    public function testRunMultipleRows()
    {
        $runner = $this->getRunner();
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        self::assertTrue($this->getClient()->tableExists('in.c-docker-test.mytable'));
        self::assertTrue($this->getClient()->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunMultipleRowsFiltered()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-1');
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-2');
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner = $this->getRunner();
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            'row-2'
        );
        self::assertTrue($this->getClient()->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunUnknownRow()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-1');
        $jobDefinitions = [$jobDefinition1];
        $runner = $this->getRunner();
        self::expectException(UserException::class);
        self::expectExceptionMessage('Row row-2 not found.');
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            'row-2'
        );
    }

    public function testRunEmptyJobDefinitions()
    {
        $runner = $this->getRunner();
        $runner->run(
            [],
            'run',
            'run',
            '1234567'
        );
    }

    public function testRunDisabled()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'disabled-row', true);
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner = $this->getRunner();
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains(
            'Skipping disabled configuration: my-config, version: 1, row: disabled-row'
        ));
        self::assertTrue($this->getClient()->tableExists('in.c-docker-test.mytable'));
        self::assertFalse($this->getClient()->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunRowDisabled()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'disabled-row', true);
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner = $this->getRunner();
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            'disabled-row'
        );
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains(
            'Force running disabled configuration: my-config, version: 1, row: disabled-row'
        ));
        self::assertTrue($this->getClient()->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRowMetadata()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'config', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'config', null, [], 'row-2');

        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner = $this->getRunner();
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $metadata = new Metadata($this->getClient());
        $table1Metadata = $this->getMetadataValues($metadata->listTableMetadata('in.c-docker-test.mytable'));
        $table2Metadata = $this->getMetadataValues($metadata->listTableMetadata('in.c-docker-test.mytable-2'));

        self::assertArrayHasKey('KBC.createdBy.component.id', $table1Metadata['system']);
        self::assertArrayHasKey('KBC.createdBy.configuration.id', $table1Metadata['system']);
        self::assertArrayHasKey('KBC.createdBy.configurationRow.id', $table1Metadata['system']);
        self::assertEquals('docker-demo', $table1Metadata['system']['KBC.createdBy.component.id']);
        self::assertEquals('config', $table1Metadata['system']['KBC.createdBy.configuration.id']);
        self::assertEquals('row-1', $table1Metadata['system']['KBC.createdBy.configurationRow.id']);

        self::assertArrayHasKey('KBC.createdBy.component.id', $table2Metadata['system']);
        self::assertArrayHasKey('KBC.createdBy.configuration.id', $table2Metadata['system']);
        self::assertArrayHasKey('KBC.createdBy.configurationRow.id', $table2Metadata['system']);
        self::assertEquals('docker-demo', $table2Metadata['system']['KBC.createdBy.component.id']);
        self::assertEquals('config', $table2Metadata['system']['KBC.createdBy.configuration.id']);
        self::assertEquals('row-2', $table2Metadata['system']['KBC.createdBy.configurationRow.id']);
    }

    public function testExecutorStoreRowState()
    {
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('docker-demo');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('test-configuration');
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'echo "{\"baz\": \"bar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobDefinition1 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-2');
        $runner = $this->getRunner();
        $runner->run(
            [$jobDefinition1, $jobDefinition2],
            'run',
            'run',
            '1234567'
        );

        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');

        self::assertEquals([], $configuration['state']);
        self::assertEquals(['baz' => 'bar'], $configuration['rows'][0]['state']);
        self::assertEquals(['baz' => 'bar'], $configuration['rows'][1]['state']);
    }

    public function testExecutorStoreRowStateWithProcessor()
    {
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('docker-demo');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('test-configuration');
        $component->addConfiguration($configuration);

        $configData = [
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files'
                        ],
                        'parameters' => [
                            'direction' => 'tables'
                        ]
                    ]
                ]
            ]
        ];

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configurationRow->setConfiguration($configData);
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $configurationRow->setConfiguration($configData);
        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'echo "{\"baz\": \"bar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobDefinition1 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-2');
        $runner = $this->getRunner();
        $runner->run(
            [$jobDefinition1, $jobDefinition2],
            'run',
            'run',
            '1234567'
        );

        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');

        self::assertEquals([], $configuration['state']);
        self::assertEquals(['baz' => 'bar'], $configuration['rows'][0]['state']);
        self::assertEquals(['baz' => 'bar'], $configuration['rows'][1]['state']);
    }

    public function testOutput()
    {
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner = $this->getRunner();
        $outputs = $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        self::assertCount(2, $outputs);
        self::assertCount(1, $outputs[0]->getImages());
        self::assertCount(1, $outputs[1]->getImages());
    }
}
