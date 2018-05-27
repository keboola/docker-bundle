<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

abstract class BaseRunnerTest extends TestCase
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
     * @var Client
     */
    private $client;

    /**
     * @var Client
     */
    private $clientMock;

    /**
     * @var JobMapper
     */
    private $jobMapperStub;

    /**
     * @var StorageApiService
     */
    private $storageServiceStub;

    /**
     * @var LoggersService
     */
    private $loggersServiceStub;

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
        $this->jobMapperStub = null;
        $this->storageServiceStub = null;
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

    protected function getLoggersService()
    {
        return $this->loggersServiceStub;
    }

    protected function getClient()
    {
        return $this->client;
    }

    protected function setClientMock($clientMock)
    {
        $this->clientMock = $clientMock;
    }

    protected function setJobMapperMock($jobMapperMock)
    {
        $this->jobMapperStub = $jobMapperMock;
    }

    protected function getStorageService()
    {
        return $this->storageServiceStub;
    }

    protected function getRunner()
    {
        $this->containerHandler = new TestHandler();
        $this->runnerHandler = new TestHandler();
        if ($this->clientMock) {
            $storageClientStub = $this->clientMock;
        } else {
            $storageClientStub = $this->client;
        }
        if (!$this->jobMapperStub) {
            $this->jobMapperStub = self::getMockBuilder(JobMapper::class)
                ->disableOriginalConstructor()
                ->getMock();
        }

        $this->storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($storageClientStub));
        $this->storageServiceStub->expects(self::any())
            ->method("getTokenData")
            ->will(self::returnValue($storageClientStub->verifyToken()));

        $log = new Logger("test-logger", [$this->runnerHandler]);
        $containerLogger = new ContainerLogger("test-container-logger", [$this->containerHandler]);
        $this->loggersServiceStub = self::getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggersServiceStub->expects(self::any())
            ->method("getLog")
            ->will($this->returnValue($log));
        $this->loggersServiceStub->expects(self::any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger));

        /** @var StorageApiService $storageServiceStub */
        return new Runner(
            $this->encryptorFactory,
            $this->storageServiceStub,
            $this->loggersServiceStub,
            $this->jobMapperStub,
            "dummy",
            ['cpu_count' => 2],
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
    }
}
