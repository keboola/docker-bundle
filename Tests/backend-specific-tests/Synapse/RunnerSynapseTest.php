<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;

class RunnerSynapseTest extends BaseRunnerTest
{
    const ABS_TEST_FILE_TAG = 'abs-workspace-runner-test';

    private function clearBuckets()
    {
        foreach (['in.c-synapse-runner-test', 'out.c-synapse-runner-test'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    private function clearFiles()
    {
        $fileList = $this->client->listFiles((new ListFilesOptions())->setTags([
            self::ABS_TEST_FILE_TAG,
            "componentId: keboola.runner-workspace-abs-test",
        ]));
        foreach ($fileList as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    protected function initStorageClient()
    {
        $this->client = new Client([
            'url' => STORAGE_API_URL_SYNAPSE,
            'token' => STORAGE_API_TOKEN_SYNAPSE,
        ]);
    }

    public function setUp()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
        parent::setUp();
    }

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('synapse-runner-test', Client::STAGE_IN, 'Docker TestSuite', 'synapse');
        $this->getClient()->createBucket('synapse-runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'synapse');
    }

    /**
     * @param array $componentData
     * @param $configId
     * @param array $configData
     * @param array $state
     * @return JobDefinition[]
     */
    protected function prepareJobDefinitions(array $componentData, $configId, array $configData, array $state)
    {
        $jobDefinition = new JobDefinition($configData, new Component($componentData), $configId, 'v123', $state);
        return [$jobDefinition];
    }

    public function testWorkspaceSynapseMapping()
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-synapse-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-workspace-synapse-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-synapse',
                    'output' => 'workspace-synapse',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-synapse-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-synapse-runner-test.new-table'));
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-synapse-runner-test.mytable',
                                    'destination' => 'local-table',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'local-table-out',
                                    'destination' => 'out.c-synapse-runner-test.new-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'copy-synapse',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-synapse-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        self::assertTrue($this->client->tableExists('out.c-synapse-runner-test.new-table'));
        $components->deleteConfiguration('keboola.runner-workspace-synapse-test', $configId);
    }

    public function testAbsWorkspaceMapping()
    {
        $this->clearFiles();
        $temp = new Temp();
        $temp->initRunFolder();
        file_put_contents($temp->getTmpFolder() . '/my-lovely-file.wtf', 'some data');
        $fileId = $this->getClient()->uploadFile(
            $temp->getTmpFolder() . '/my-lovely-file.wtf',
            (new FileUploadOptions())
                ->setTags([self::ABS_TEST_FILE_TAG])
        );

        $componentData = [
            'id' => 'keboola.runner-workspace-abs-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'files' => [
                                [
                                    'tags' => [self::ABS_TEST_FILE_TAG],
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'list-abs',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains(sprintf('data/in/files/my_lovely_file.wtf/%s', $fileId)));

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingCombined()
    {
        $this->clearFiles();
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-synapse-runner-test', 'mytable3', $csv);
        unset($csv);

        $temp = new Temp();
        $temp->initRunFolder();
        file_put_contents($temp->getTmpFolder() . '/my-lovely-file.wtf', 'some data');
        $fileId = $this->getClient()->uploadFile(
            $temp->getTmpFolder() . '/my-lovely-file.wtf',
            (new FileUploadOptions())
                ->setTags([self::ABS_TEST_FILE_TAG])
        );

        $componentData = [
            'id' => 'keboola.runner-workspace-abs-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-synapse-runner-test.mytable3',
                                    'destination' => 'mytable3.csv',
                                ],
                            ],
                            'files' => [
                                [
                                    'tags' => [self::ABS_TEST_FILE_TAG],
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'list-abs',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains(
            sprintf('data/in/files/my_lovely_file.wtf/%s', $fileId)
        ));
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains('data/in/tables/mytable3.csvmanifest'));
        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingOutput()
    {
        $this->clearBuckets();
        $componentData = [
            'id' => 'keboola.runner-workspace-abs-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'my-table.csv',
                                    "destination" => "out.c-synapse-runner-test.test-table",
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-abs-table',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $data = $this->client->getTableDataPreview('out.c-synapse-runner-test.test-table');
        self::assertEquals("\"first\",\"second\"\n\"1a\",\"2b\"\n", $data);

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingFilesOutput()
    {
        $this->clearBuckets();
        $componentData = [
            'id' => 'keboola.runner-workspace-abs-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user', 'allow-use-file-storage-only'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'files' => [
                                [
                                    'processed_tags' => ['processed'],
                                ],
                            ],
                        ],
                        'output' => [
                            'files' => [
                                [
                                    'source' => 'my-file.dat',
                                    'tags' => [self::ABS_TEST_FILE_TAG],
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-abs-file',
                    ],
                    'runtime' => [
                        'use_file_storage_only' => true,
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        // wait for the file to show up in the listing
        sleep(2);
        $fileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:' . self::ABS_TEST_FILE_TAG .
            ' AND tags:"componentId: keboola.runner-workspace-abs-test" AND tags:' .
            sprintf('"configurationId: %s"', $configId)
        ));
        $this->assertCount(1, $fileList);
        $this->assertEquals('my-file.dat', $fileList[0]['name']);
        $this->assertContains('processed', $fileList[0]['tags']);

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceOutputTablesAsFiles()
    {
        $this->clearBuckets();
        $componentData = [
            'id' => 'keboola.runner-workspace-abs-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
            // https://keboola.slack.com/archives/C02C3GZUS/p1598942156005100
            // https://github.com/microsoft/msphpsql/issues/400#issuecomment-481722255
            'features' => ['container-root-user', 'allow-use-file-storage-only'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'my-table.csv',
                                    "destination" => "out.c-synapse-runner-test.test-table",
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-abs-table',
                    ],
                    'runtime' => [
                        'use_file_storage_only' => true,
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        // wait for the file to show up in the listing
        sleep(2);

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-synapse-runner-test.test-table'));

        // but the file should exist
        $fileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"componentId: keboola.runner-workspace-abs-test" AND tags:' .
            sprintf('"configurationId: %s"', $configId)
        ));
        $this->assertCount(1, $fileList);
        $this->assertEquals('my_table.csv', $fileList[0]['name']);

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }
}
