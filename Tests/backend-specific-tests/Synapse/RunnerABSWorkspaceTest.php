<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
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

class RunnerABSWorkspaceTest extends BaseRunnerTest
{
    const ABS_TEST_FILE_TAG = 'abs-workspace-runner-test';

    public function setUp()
    {
        if (!RUN_SYNAPSE_TESTS) {
            return;
        }
        $this->client = new Client([
            'url' => STORAGE_API_URL_SYNAPSE,
            'token' => STORAGE_API_TOKEN_SYNAPSE,
        ]);
        $components = new Components($this->client);
        $workspaces = new Workspaces($this->client);
        $options = new ListComponentConfigurationsOptions();
        $options->setComponentId('keboola.runner-workspace-test');
        foreach ($components->listComponentConfigurations($options) as $configuration) {
            $wOptions = new ListConfigurationWorkspacesOptions();
            $wOptions->setComponentId('keboola.runner-workspace-test');
            $wOptions->setConfigurationId($configuration['id']);
            foreach ($components->listConfigurationWorkspaces($wOptions) as $workspace) {
                $workspaces->deleteWorkspace($workspace['id']);
            }
            $components->deleteConfiguration('keboola.runner-workspace-test', $configuration['id']);
        }
        parent::setUp();
    }

    private function createBuckets()
    {
        // Create buckets
        $this->getClient()->createBucket('abs-workspace-runner-test', Client::STAGE_IN, 'Docker TestSuite', 'synapse');
        $this->getClient()->createBucket('abs-workspace-runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'synapse');
    }

    private function clearBuckets()
    {
        foreach (['in.c-abs-workspace-runner-test', 'out.c-abs-workspace-runner-test'] as $bucket) {
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
        $fileList = $this->getClient()->listFiles(
            (new ListFilesOptions())->setTags([self::ABS_TEST_FILE_TAG, 'foo', 'bar'])
        );
        foreach ($fileList as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    public function testAbsWorkspaceMapping()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
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
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains(sprintf('data/in/files/%s_my_lovely_file.wtf/%s', $fileId, $fileId)));

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingCombined()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
        $this->clearFiles();
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-abs-workspace-runner-test', 'mytable3', $csv);
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
                                    'source' => 'in.c-abs-workspace-runner-test.mytable3',
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
            sprintf('data/in/files/%s_my_lovely_file.wtf/%s', $fileId, $fileId)
        ));
        self::assertTrue($this->getContainerHandler()->hasInfoThatContains('data/in/tables/mytable3.csvmanifest'));
        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingTableOutput()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
        $this->clearBuckets();
        $this->createBuckets();
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
                                    "destination" => "out.c-abs-workspace-runner-test.output-test-table",
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
        $data = $this->getClient()->getTableDataPreview('out.c-abs-workspace-runner-test.output-test-table');
        self::assertEquals("\"first\",\"second\"\n\"1a\",\"2b\"\n", $data);

        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testAbsWorkspaceMappingFileOutput()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
        $this->clearFiles();
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
                            'tables' => [],
                            "files" => [],
                        ],
                        'output' => [
                            'tables' => [],
                            "files" => [],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-abs-file',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        // the create-abs-file should create an output file and that should be in our storage
        $files = $this->getClient()->listFiles((new ListFilesOptions())->setTags(['foo', 'bar']));

        // all the test files should be cleared, so the only file there should be the one made by create-abs-file
        $this->assertEquals('my_file.dat', $files[0]['name']);
        // assert the workspace is removed
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-abs-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }
}
