<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\Temp\Temp;

class RunnerSynapseTest extends BaseTableBackendTest
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

    private function clearConfigs()
    {
        $componentsApi = new Components($this->client);
        $configurations = $componentsApi->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())->setComponentId('keboola.runner-workspace-synapse-test')
        );
        $workspacesApi = new Workspaces($this->client);
        foreach ($configurations as $configuration) {
            $workspaces = $componentsApi->listConfigurationWorkspaces(
                (new ListConfigurationWorkspacesOptions())
                    ->setComponentId('keboola.runner-workspace-synapse-test')
                    ->setConfigurationId($configuration['id'])
            );
            foreach ($workspaces as $workspace) {
                $workspacesApi->deleteWorkspace($workspace['id']);
            }
            $componentsApi->deleteConfiguration('keboola.runner-workspace-synapse-test', $configuration['id']);
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

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('synapse-runner-test', Client::STAGE_IN, 'Docker TestSuite', 'synapse');
        $this->getClient()->createBucket('synapse-runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'synapse');
    }

    public function testWorkspaceSynapseMapping()
    {
        $this->clearBuckets();
        $this->createBuckets();
        $this->clearConfigs();
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
        $outputs = [];
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
            new NullUsageFile(),
            [],
            $outputs,
            null
        );

        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-synapse-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        self::assertTrue($this->client->tableExists('out.c-synapse-runner-test.new-table'));
    }

    public function testAbsWorkspaceMapping()
    {
        $this->clearFiles();
        $this->clearConfigs();
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

        $outputs = [];
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
                [],
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null
        );
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains(sprintf('data/in/files/my_lovely_file.wtf/%s', $fileId)));

        // assert the workspace is preserved
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(1, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingCombined()
    {
        $this->clearFiles();
        $this->clearBuckets();
        $this->clearConfigs();
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

        $outputs = [];
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
            new NullUsageFile(),
            [],
            $outputs,
            null
        );
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains(
            sprintf('data/in/files/my_lovely_file.wtf/%s', $fileId)
        ));
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains('data/in/tables/mytable3.csvmanifest'));
        // assert the workspace is preserved
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(1, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingOutput()
    {
        $this->clearBuckets();
        $this->clearConfigs();
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

        $outputs = [];
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
                                    'destination' => 'out.c-synapse-runner-test.test-table',
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
            new NullUsageFile(),
            [],
            $outputs,
            null
        );
        $data = $this->client->getTableDataPreview('out.c-synapse-runner-test.test-table');
        self::assertEquals("\"first\",\"second\"\n\"1a\",\"2b\"\n", $data);

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(1, $components->listConfigurationWorkspaces($options));
    }

    public static function expectedDefaultTableBackend(): string
    {
        return 'synapse';
    }
}
