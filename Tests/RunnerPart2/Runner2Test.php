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
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Symfony\Component\Process\Process;
use Throwable;

class Runner2Test extends BaseRunnerTest
{
    use ReflectionPropertyAccessTestCase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        $client = new StorageApiClient([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $transformationTestComponentId = 'keboola.python-transformation';
        $components = new Components($client);
        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId($transformationTestComponentId)
        );
        foreach ($configurations as $configuration) {
            try {
                $components->deleteConfiguration($transformationTestComponentId, $configuration['id']);
            } catch (Throwable $e) {
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
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
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
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
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

    public function testArtifactsUpload()
    {
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->onlyMethods(['verifyToken', 'listFiles'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $storageApiMock->method('listFiles')->willReturn([]);
        $this->setClientMock($storageApiMock);

        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    'path = "/data/artifacts/current"',
                    'if not os.path.exists(path):os.makedirs(path)',
                    'with open("/data/artifacts/current/myartifact1", "w") as file:',
                    '   file.write("value1")',
                ],
            ],
        ];

        $components = new Components($storageApiMock);
        $configuration = $components->addConfiguration(
            (new Configuration())
                ->setComponentId('keboola.python-transformation')
                ->setConfiguration($config)
                ->setName('artifacts tests')
        );

        $configId = $configuration['id'];
        $jobId = rand(0, 999999);

        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                $config,
                []
            ),
            'run',
            'run',
            $jobId,
            new NullUsageFile(),
            [],
            $outputs,
            null
        );

        sleep(2);

        $files = $this->client->listFiles(
            (new ListFilesOptions())
                ->setTags([
                    'artifacts',
                    'branchId-default',
                    'componentId-keboola.python-transformation',
                    'configId-' . $configId,
                    'jobId-' . $jobId,
                ])
                ->setLimit(1)
        );

        self::assertEquals('artifacts.tar.gz', $files[0]['name']);
        self::assertContains('branchId-default', $files[0]['tags']);
        self::assertContains('componentId-keboola.python-transformation', $files[0]['tags']);
        self::assertContains('configId-' . $configId, $files[0]['tags']);
        self::assertContains('jobId-' . $jobId, $files[0]['tags']);

        /** @var Output $output */
        $output = $outputs[0];
        self::assertSame([
            'storageFileId' => $files[0]['id'],
        ], $output->getArtifactUploaded());
    }

    public function testArtifactsUploadNull()
    {
        $tokenRes = $this->client->verifyToken();
        $projectId = $tokenRes['owner']['id'];

        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->onlyMethods(['verifyToken', 'listFiles'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $storageApiMock->method('listFiles')->willReturn([]);
        $this->setClientMock($storageApiMock);

        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    '# do nothing'
                ],
            ],
        ];

        $components = new Components($storageApiMock);
        $configuration = $components->addConfiguration(
            (new Configuration())
                ->setComponentId('keboola.python-transformation')
                ->setConfiguration($config)
                ->setName('artifacts tests')
        );

        $configId = $configuration['id'];
        $jobId = rand(0, 999999);

        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                $config,
                []
            ),
            'run',
            'run',
            $jobId,
            new NullUsageFile(),
            [],
            $outputs,
            null
        );

        sleep(2);

        var_dump('project id ' . $projectId);
        var_dump($configId);
        var_dump($jobId);

        $files = $this->client->listFiles(
            (new ListFilesOptions())
                ->setTags([
                    'artifacts',
                    'branchId-default',
                    'componentId-keboola.python-transformation',
                    'configId-' . $configId,
                    'jobId-' . $jobId,
                ])
                ->setLimit(1)
        );

        var_dump($files);

        self::assertEmpty($files);

        /** @var Output $output */
        $output = $outputs[0];
        self::assertNull($output->getArtifactUploaded());
    }

    public function testArtifactsDownload()
    {
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->onlyMethods(['verifyToken'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $this->setClientMock($storageApiMock);

        $previousJobId = rand(0, 999999);
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    sprintf('with open("/data/artifacts/runs/jobId-%s/artifact1", "r") as f:', $previousJobId),
                    '   print(f.read())',
                ],
            ],
            'artifacts' => [
                'runs' => [
                    'enabled' => true,
                    'filter' => [
                        'limit' => 1,
                    ],
                ],
            ],
        ];
        $components = new Components($storageApiMock);
        $configuration = $components->addConfiguration(
            (new Configuration())
                ->setComponentId('keboola.python-transformation')
                ->setConfiguration($config)
                ->setName('artifacts tests')
        );
        $configId = $configuration['id'];

        if (!is_dir('/tmp/artifact/')) {
            mkdir('/tmp/artifact/');
        }
        file_put_contents('/tmp/artifact/artifact1', 'value1');

        $process = new Process([
            'tar',
            '-C',
            '/tmp/artifact',
            '-czvf',
            '/tmp/artifacts.tar.gz',
            '.',
        ]);
        $process->mustRun();

        $uploadedFileId = $this->client->uploadFile(
            '/tmp/artifacts.tar.gz',
            (new FileUploadOptions())
                ->setTags([
                    'artifact',
                    'branchId-default',
                    'componentId-keboola.python-transformation',
                    'configId-' . $configId,
                    'jobId-' . $previousJobId,
                ])
        );

        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        sleep(2);

        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
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

        /** @var Output $output */
        $output = $outputs[0];
        self::assertStringContainsString('value1', $output->getProcessOutput());
        self::assertSame([
            ['storageFileId' => $uploadedFileId],
        ], $output->getArtifactsDownloaded());
    }

    public function testArtifactsDownloadEmpty()
    {
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->onlyMethods(['verifyToken'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $this->setClientMock($storageApiMock);

        $previousJobId = rand(0, 999999);
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    '# do nothing'
                ],
            ],
            'artifacts' => [
                'runs' => [
                    'enabled' => false,
                ],
            ],
        ];
        $components = new Components($storageApiMock);
        $configuration = $components->addConfiguration(
            (new Configuration())
                ->setComponentId('keboola.python-transformation')
                ->setConfiguration($config)
                ->setName('artifacts tests')
        );
        $configId = $configuration['id'];

        if (!is_dir('/tmp/artifact/')) {
            mkdir('/tmp/artifact/');
        }

        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        sleep(2);

        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
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

        /** @var Output $output */
        $output = $outputs[0];
        self::assertSame([], $output->getArtifactsDownloaded());
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
