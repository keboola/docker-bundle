<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\AuthorizationService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Keboola\DockerBundle\Job\Metadata\JobFactory;

class UsageFileTest extends KernelTestCase
{
    /**
     * @var Client
     */
    private $storageApiClient;

    public function setUp()
    {
        $this->storageApiClient = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);

        self::bootKernel();
    }

    public function testExecutorStoreUsage()
    {
        $tokenInfo = $this->storageApiClient->verifyToken();
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($this->storageApiClient));
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenInfo));

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger));

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );

        /** @var $jobMapper JobMapper */
        $jobMapper = self::$kernel->getContainer()
            ->get('syrup.elasticsearch.current_component_job_mapper');

        /** @var LoggersService $loggersServiceStub */
        /** @var StorageApiService $storageServiceStub */
        $runner = new Runner(
            $encryptorFactory,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapper, // using job mapper from container here
            new AuthorizationService($encryptorFactory, $storageServiceStub, "dummy"),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );

        $component = new Components($this->storageApiClient);
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
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker Usage file test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => <<<CMD
echo '[{"metric": "kB", "value": 150}]' > /data/out/usage.json
CMD
                        ,
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobFactory = new JobFactory('docker-bundle', $encryptorFactory, $storageServiceStub);

        $job = $jobFactory->create('run', [
            'configData' => [],
            'component' =>
                'docker-demo'
        ], uniqid());

        $jobId = $jobMapper->create($job);

        $jobDefinition = new JobDefinition([], new Component($componentData), 'test-configuration');

        $runner->run([$jobDefinition], 'run', 'run', $jobId);

        $job = $jobMapper->get($jobId);
        $this->assertEquals([
            [
                'metric' => 'kB',
                'value' => 150
            ]
        ], $job->getUsage());

        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testExecutorStoreRowsUsage()
    {
        $tokenInfo = $this->storageApiClient->verifyToken();
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($this->storageApiClient));
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenInfo));

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger));

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );

        /** @var $jobMapper JobMapper */
        $jobMapper = self::$kernel->getContainer()
            ->get('syrup.elasticsearch.current_component_job_mapper');

        /** @var LoggersService $loggersServiceStub */
        /** @var StorageApiService $storageServiceStub */
        $runner = new Runner(
            $encryptorFactory,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapper, // using job mapper from container here
            new AuthorizationService($encryptorFactory, $storageServiceStub, "dummy"),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );

        $component = new Components($this->storageApiClient);
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
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker Usage file test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => <<<CMD
echo '[{"metric": "kB", "value": 150}]' > /data/out/usage.json
CMD
                        ,
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobFactory = new JobFactory('docker-bundle', $encryptorFactory, $storageServiceStub);

        $job = $jobFactory->create('run', [
            'configData' => [],
            'component' =>
                'docker-demo'
        ], uniqid());

        $jobId = $jobMapper->create($job);

        $jobDefinition1 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-2');

        $runner->run([$jobDefinition1, $jobDefinition2], 'run', 'run', $jobId);

        $job = $jobMapper->get($jobId);
        $this->assertEquals([
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
