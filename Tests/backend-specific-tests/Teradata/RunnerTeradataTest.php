<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\Teradata;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\Runner\BaseTableBackendTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\Temp\Temp;

class RunnerTeradataTest extends BaseTableBackendTest
{
    private const COMPONENT_ID = 'keboola.runner-workspace-teradata-test';

    private function clearBuckets()
    {
        foreach (['in.c-teradata-runner-test', 'out.c-teradata-runner-test'] as $bucket) {
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
            (new ListComponentConfigurationsOptions())->setComponentId(self::COMPONENT_ID),
        );
        $workspacesApi = new Workspaces($this->client);
        foreach ($configurations as $configuration) {
            $workspaces = $componentsApi->listConfigurationWorkspaces(
                (new ListConfigurationWorkspacesOptions())
                    ->setComponentId(self::COMPONENT_ID)
                    ->setConfigurationId($configuration['id']),
            );
            foreach ($workspaces as $workspace) {
                $workspacesApi->deleteWorkspace($workspace['id'], [], true);
            }
            $componentsApi->deleteConfiguration(self::COMPONENT_ID, $configuration['id']);
        }
    }

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('teradata-runner-test', Client::STAGE_IN, 'Docker TestSuite', 'teradata');
        $this->getClient()->createBucket('teradata-runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'teradata');
    }

    public function testWorkspaceTeradataMapping()
    {
        $this->clearBuckets();
        $this->createBuckets();
        $this->clearConfigs();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-teradata-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => self::COMPONENT_ID,
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-teradata',
                    'output' => 'workspace-teradata',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId(self::COMPONENT_ID);
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-teradata-runner-test.new-table'));
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
                                    'source' => 'local-table-out',
                                    'destination' => 'out.c-teradata-runner-test.new-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'copy-teradata',
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
            null,
        );

        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId(self::COMPONENT_ID);
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        self::assertTrue($this->client->tableExists('out.c-teradata-runner-test.new-table'));
    }

    public static function expectedDefaultTableBackend(): string
    {
        return 'teradata';
    }
}
