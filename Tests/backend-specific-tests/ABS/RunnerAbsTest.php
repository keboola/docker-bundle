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
use Keboola\Temp\Temp;

class RunnerAbsTest extends BaseRunnerTest
{
    private function clearBuckets()
    {
        foreach (['in.c-runner-test', 'out.c-runner-test', 'in.c-keboola-docker-demo-sync-runner-configuration'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('runner-test', Client::STAGE_IN, 'Docker TestSuite', 'snowflake');
        $this->getClient()->createBucket('runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'snowflake');
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

    public function testAbsStagingMapping()
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-staging-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                    'tag' => '0.0.3',
                ],
                'staging_storage' => [
                    'input' => 'abs',
                    'output' => 'local',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-staging-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
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
                    ],
                    'parameters' => [
                        'operation' => 'content',
                        'filename' => 'local-table.manifest',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        self::assertCount(1, $records);
        $message = $records[0]['message'];
        $manifestData = json_decode($message, true);
        self::assertEquals('in.c-runner-test.mytable', $manifestData['id']);
        self::assertEquals('mytable', $manifestData['name']);
        self::assertArrayHasKey('last_change_date', $manifestData);
        self::assertArrayNotHasKey('s3', $manifestData);
        self::assertArrayHasKey('abs', $manifestData);
        self::assertArrayHasKey('region', $manifestData['abs']);
        self::assertArrayHasKey('container', $manifestData['abs']);
        self::assertArrayHasKey('name', $manifestData['abs']);
        self::assertArrayHasKey('sas_connection_string', $manifestData['abs']['credentials']);
        $components->deleteConfiguration('keboola.runner-staging-test', $configId);
    }
}
