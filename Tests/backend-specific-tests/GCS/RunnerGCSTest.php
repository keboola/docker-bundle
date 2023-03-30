<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\GCS;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\DockerBundle\Tests\Runner\BackendAssertsTrait;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\Temp\Temp;

class RunnerGCSTest extends BaseRunnerTest
{
    use BackendAssertsTrait;

    public function setUp(): void
    {
        parent::setUp();

        self::assertFileBackend('gcp', $this->client);
    }

    private function clearBuckets()
    {
        foreach (['in.c-snowflake-runner-test', 'out.c-snowflake-runner-test'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true, 'async' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
    }

    private function clearConfigs()
    {
        $componentsApi = new Components($this->client);
        $configurations = $componentsApi->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())->setComponentId('keboola.runner-workspace-test')
        );
        $workspacesApi = new Workspaces($this->client);
        foreach ($configurations as $configuration) {
            $workspaces = $componentsApi->listConfigurationWorkspaces(
                (new ListConfigurationWorkspacesOptions())
                    ->setComponentId('keboola.runner-workspace-test')
                    ->setConfigurationId($configuration['id'])
            );
            foreach ($workspaces as $workspace) {
                $workspacesApi->deleteWorkspace($workspace['id'], [], true);
            }
            $componentsApi->deleteConfiguration('keboola.runner-workspace-test', $configuration['id']);
        }
    }

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('snowflake-runner-test', Client::STAGE_IN, 'Docker TestSuite', 'snowflake');
        $this->getClient()->createBucket('snowflake-runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'snowflake');
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

    public function testWorkspaceSnowflakeMapping()
    {
        $this->clearBuckets();
        $this->createBuckets();
        $this->clearConfigs();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-snowflake-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-snowflake-runner-test.new-table'));
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
                                    'source' => 'in.c-snowflake-runner-test.mytable',
                                    'destination' => 'local-table',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'local-table-out',
                                    'destination' => 'out.c-snowflake-runner-test.new-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'copy',
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
        $options->setComponentId('keboola.runner-workspace-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        self::assertTrue($this->client->tableExists('out.c-snowflake-runner-test.new-table'));
    }
}
