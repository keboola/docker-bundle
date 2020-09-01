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
use Keboola\Temp\Temp;

class RunnerSynapseTest extends BaseRunnerTest
{
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
            return;
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

    public function testWorkspaceMapping()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test is disabled.');
        }
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
}
