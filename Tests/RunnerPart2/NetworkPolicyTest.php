<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;

class NetworkPolicyTest extends BaseRunnerTest
{
    public function testWorkspaceMapping(): void
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
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
        $runner = $this->getRunner(self::getOptionalEnv('STORAGE_API_TOKEN_NETWORK_POLICY'));

        self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
        $outputs = [];
        $this->expectException(ApplicationException::class);
        $message = '/Incoming request with IP\/Token (\d{1,3}\.){3}\d{1,3} is not allowed to access Snowflake/';
        $this->expectExceptionMessageMatches($message);
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-runner-test.mytable',
                                    'destination' => 'local-table',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'local-table',
                                    'destination' => 'out.c-runner-test.new-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'copy',
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
    }

    private function clearBuckets(): void
    {
        $buckets = ['in.c-runner-test', 'out.c-runner-test', 'in.c-keboola-docker-demo-sync-runner-configuration'];
        foreach ($buckets as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true, 'async' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
    }

    private function createBuckets(): void
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('runner-test', Client::STAGE_IN, 'Docker TestSuite', 'snowflake');
        $this->getClient()->createBucket('runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'snowflake');
    }

    protected function initStorageClient(): void
    {
        $this->client = new BranchAwareClient(
            'default',
            [
                'url' => self::getOptionalEnv('STORAGE_API_URL'),
                'token' => self::getOptionalEnv('STORAGE_API_TOKEN_NETWORK_POLICY'),
            ],
        );
    }
}
