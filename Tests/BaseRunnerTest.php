<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

abstract class BaseRunnerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var TestHandler
     */
    private $containerHandler;

    /**
     * @var TestHandler
     */
    private $runnerHandler;

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * @var string
     */
    private $configId;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var array
     */
    private $configuration;

    /**
     * @var array
     */
    private $tokenInfo;

    /**
     * @var array
     */
    private $services;

    /**
     * @var array
     */
    private $components;

    /**
     * @var Client
     */
    private $client;

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $this->containerHandler = null;
        $this->runnerHandler = null;
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]
        );
        $this->configId = '';
        $this->componentId = '';
        $this->configuration = [];
        $this->tokenInfo = [];
        $this->services = [];
        $this->components = [];
    }

    protected function getEncryptorFactory()
    {
        return $this->encryptorFactory;
    }

    protected function getRunnerHandler()
    {
        return $this->runnerHandler;
    }

    protected function getContainerHandler()
    {
        return $this->containerHandler;
    }

    protected function getClient()
    {
        return $this->client;
    }

    protected function getRunner()
    {
        $this->containerHandler = new TestHandler();
        $this->runnerHandler = new TestHandler();
        $this->encryptorFactory->setComponentId($this->componentId);
        $this->encryptorFactory->setConfigurationId($this->configId);

        /*
        $storageClientStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub->expects($this->any())
            ->method('indexAction')
            ->will($this->returnValue(['components' => $this->components, 'services' => $this->services]));
        */
        $storageClientStub = $this->client;

        $storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($storageClientStub));
        $storageServiceStub->expects(self::any())
            ->method("getTokenData")
            ->will(self::returnValue($this->tokenInfo));

        $log = new Logger("test-logger", [$this->runnerHandler]);
        $containerLogger = new ContainerLogger("test-container-logger", [$this->containerHandler]);
        $loggersServiceStub = self::getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects(self::any())
            ->method("getLog")
            ->will($this->returnValue($log));
        $loggersServiceStub->expects(self::any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger));

        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $componentsStub = self::getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects(self::any())
            ->method("getConfiguration")
            ->with($this->componentId, $this->configId)
            ->will(self::returnValue($this->configuration));

        $componentsServiceStub = self::getMockBuilder(ComponentsService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsServiceStub->expects(self::any())
            ->method("getComponents")
            ->will(self::returnValue($componentsStub));

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        /** @var JobMapper $jobMapperStub */
        return new Runner(
            $this->encryptorFactory,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            "dummy",
            ['cpu_count' => 2],
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
    }
}
