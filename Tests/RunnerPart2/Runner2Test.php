<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\DockerBundle\Tests\ReflectionPropertyAccessTestCase;
use Keboola\Sandboxes\Api\Client as SandboxesApiClient;
use Keboola\Sandboxes\Api\Project;
use Keboola\StorageApi\BranchAwareClient as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Throwable;

class Runner2Test extends BaseRunnerTest
{
    use ReflectionPropertyAccessTestCase;

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        $client = new StorageApiClient(
            'default',
            [
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('STORAGE_API_TOKEN'),
            ]
        );
        $transformationTestComponentId = 'keboola.python-transformation';
        $components = new Components($client);
        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId($transformationTestComponentId)
        );
        foreach ($configurations as $configuration) {
            try {
                $components->deleteConfiguration($transformationTestComponentId, $configuration['id']);
            } catch (Throwable) {
                // do nothing
            }
        }
    }

    public function testPermissionsFailedWithoutContainerRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
            $outputs,
            null
        );
    }

    public function testMlflowAbsConnectionStringIsPassedToComponent(): void
    {
        $configId = uniqid('runner-test-');
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-config-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components = new Components($this->client);
        $components->addConfiguration($configuration);

        // set project feature
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->onlyMethods(['verifyToken'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['sandboxes-python-mlflow'],
            ],
        ]);
        $this->setClientMock($storageApiMock);

        $sandboxesApiMock = $this->createMock(SandboxesApiClient::class);
        $sandboxesApiMock->method('getProject')->willReturn(Project::fromArray([
            'id' => 'my-project',
            'mlflowUri' => 'https://mlflow', // fake URI to check in logs
            'mlflowAbsConnectionString' => 'connection-string', // fake connection string to check in logs
            'createdTimestamp' => '1638368340',
        ]));

        $runner = $this->getRunner();
        $mlflowProjectResolver = self::getPrivatePropertyValue($runner, 'mlflowProjectResolver');
        self::setPrivatePropertyValue($mlflowProjectResolver, 'sandboxesApiClient', $sandboxesApiMock);

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
            'features' => ['mlflow-artifacts-access'], // set component feature
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
            $outputs,
            null
        );

        $containerOutput = $outputs[0]->getProcessOutput();
        $containerOutput = explode("\n", $containerOutput);
        self::assertContains(
            'Environment "AZURE_STORAGE_CONNECTION_STRING" has value "connection-string".',
            $containerOutput
        );
        self::assertContains(
            'Environment "MLFLOW_TRACKING_URI" has value "https://mlflow".',
            $containerOutput
        );
    }

    public function testComponentRunsIfSandboxProjectDoesNotExist(): void
    {
        $configId = uniqid('runner-test-');
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-config-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components = new Components($this->client);
        $components->addConfiguration($configuration);

        // set project feature
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->onlyMethods(['verifyToken'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['sandboxes-python-mlflow'],
            ],
        ]);
        $this->setClientMock($storageApiMock);

        $runner = $this->getRunner();

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
            'features' => ['mlflow-artifacts-access'], // set component feature
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
            $outputs,
            null
        );

        $containerOutput = $outputs[0]->getProcessOutput();
        $containerOutput = explode("\n", $containerOutput);

        // check the component ran but no connection string was passed
        self::assertContains('Environment "KBC_PROJECTID" has value "1234".', $containerOutput);
        self::assertNotContains('Environment "AZURE_STORAGE_CONNECTION_STRING"', $containerOutput);
    }
}
