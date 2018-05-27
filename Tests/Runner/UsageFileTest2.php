<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;

class UsageFileTest2 extends BaseRunnerTest
{
    public function testExecutorStoreUsage()
    {
        $job = new Job($this->getEncryptorFactory()->getEncryptor());
        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'update'])
            ->getMock();
        $jobMapperStub->expects(self::once())
            ->method('get')
            ->with('987654')
            ->willReturn($job);
        $this->setJobMapperMock($jobMapperStub);
        $component = new Components($this->getClient());
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $configuration = new Configuration();
        $configuration->setComponentId('docker-demo');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('test-configuration');
        $component->addConfiguration($configuration);
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $configData = [
            'parameters' => [
                'script' => [
                    'with open("/data/out/usage.json", "w") as file:',
                    '   file.write(\'[{"metric": "kB", "value": 150}]\')',
                ],
            ],
        ];
        $jobDefinition = new JobDefinition($configData, new Component($componentData), 'test-configuration');
        $runner = $this->getRunner();
        $runner->run([$jobDefinition], 'run', 'run', '987654');
        self::assertEquals([
            [
                'metric' => 'kB',
                'value' => 150
            ]
        ], $job->getUsage());

        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testExecutorStoreRowsUsage()
    {
        $job = new Job($this->getEncryptorFactory()->getEncryptor());
        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'update'])
            ->getMock();
        $jobMapperStub->expects(self::atLeastOnce())
            ->method('get')
            ->with('987654')
            ->willReturn($job);
        $this->setJobMapperMock($jobMapperStub);

        $component = new Components($this->getClient());
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
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
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $configData = [
            'parameters' => [
                'script' => [
                    'with open("/data/out/usage.json", "w") as file:',
                    '   file.write(\'[{"metric": "kB", "value": 150}]\')',
                ],
            ],
        ];

        $jobDefinition1 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-2');
        $runner = $this->getRunner();
        $runner->run([$jobDefinition1, $jobDefinition2], 'run', 'run', '987654');
        self::assertEquals([
            [
                'metric' => 'kB',
                'value' => 150
            ],
            [
                'metric' => 'kB',
                'value' => 150
            ]
        ], $job->getUsage());

        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }
}
