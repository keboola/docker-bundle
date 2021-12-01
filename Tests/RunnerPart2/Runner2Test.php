<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\DockerBundle\Tests\ReflectionPropertyAccessTestCase;
use Keboola\Sandboxes\Api\Client as SandboxesApiClient;
use Keboola\Sandboxes\Api\Project;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;

class Runner2Test extends BaseRunnerTest
{
    use ReflectionPropertyAccessTestCase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function testPermissionsFailedWithoutContainerRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $config = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'mytable.csv.gz',
                            'destination' => 'in.c-runner-test.mytable',
                            'columns' => ['col1'],
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from subprocess import call',
                    'import os',
                    'os.makedirs("/data/out/tables/mytable.csv.gz")',
                    'call(["chmod", "000", "/data/out/tables/mytable.csv.gz"])',
                    'with open("/data/out/tables/mytable.csv.gz/part1", "w") as file:',
                    '   file.write("value1")',
                ],
            ],
        ];

        $runner = $this->getRunner();
        self::expectException(UserException::class);
        // touch: cannot touch '/data/out/tables/mytable.csv.gz/part1': Permission denied
        self::expectExceptionMessageMatches('/Permission denied/');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $config,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs
        );
    }

    public function testStorageFilesOutputProcessed(): void
    {
        $configId = uniqid('runner-test-');
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-config-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components = new Components($this->client);
        $components->addConfiguration($configuration);

        $sandboxesApiMock = $this->createMock(SandboxesApiClient::class);
        $sandboxesApiMock->method('getProject')->willReturn(Project::fromArray([
            'id' => 'my-project',
            'mlflowAbsConnectionString' => 'connection-string', // fake connection string to check in logs
            'createdTimestamp' => '1638368340',
        ]));

        $runner = $this->getRunner();
        self::setPrivatePropertyValue($runner, 'sandboxesApiClient', $sandboxesApiMock);

        $componentData = [
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-config-test',
                    'tag' => '0.0.16',
                ],
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
            'features' => ['mlflow-artifacts-access'],
        ];

        /** @var Output[] $outputs */
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'parameters' => [
                        'operation' => 'dump-env',
                    ],
                ],
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs
        );

        $containerOutput = $outputs[0]->getProcessOutput();
        $containerOutput = explode("\n", $containerOutput);
        self::assertContains(
            'Environment "AZURE_STORAGE_CONNECTION_STRING" has value "connection-string".',
            $containerOutput
        );
    }

    /**
     * @param array $componentData
     * @param $configId
     * @param array $configData
     * @param array $state
     * @return JobDefinition[]
     */
    private function prepareJobDefinitions(array $componentData, $configId, array $configData, array $state)
    {
        $jobDefinition = new JobDefinition($configData, new Component($componentData), $configId, 'v123', $state);
        return [$jobDefinition];
    }
}
