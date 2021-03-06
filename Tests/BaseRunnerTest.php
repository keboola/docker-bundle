<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApiBranch\ClientWrapper;
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
    protected $client;

    /**
     * @var Client
     */
    protected $clientMock;

    /**
     * @var UsageFileInterface
     */
    private $usageFile;

    /**
     * @var LoggersService
     */
    private $loggersServiceStub;

    protected function initStorageClient()
    {
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]
        );
    }

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $this->containerHandler = null;
        $this->runnerHandler = null;
        $this->encryptorFactory = new ObjectEncryptorFactory(
            AWS_KMS_TEST_KEY,
            AWS_ECR_REGISTRY_REGION,
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            ''
        );
        $this->encryptorFactory->setComponentId('keboola.docker-demo-sync');
        $this->encryptorFactory->setProjectId('12345');
        $this->encryptorFactory->setStackId('test');
        $this->initStorageClient();
        $tokenInfo = $this->client->verifyToken();
        print(sprintf(
            'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.',
            $tokenInfo['description'],
            $tokenInfo['id'],
            $tokenInfo['owner']['name'],
            $tokenInfo['owner']['id'],
            $this->client->getApiUrl()
        ));

        $this->usageFile = null;

        $this->containerHandler = new TestHandler();
        $this->runnerHandler = new TestHandler();
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

    protected function getRunner()
    {
        if ($this->clientMock) {
            $storageClientStub = $this->clientMock;
        } else {
            $storageClientStub = $this->client;
        }
        $this->usageFile = new NullUsageFile();
        $clientWrapper = new ClientWrapper($storageClientStub, null, null, ClientWrapper::BRANCH_MAIN);
        return new Runner(
            $this->encryptorFactory,
            $clientWrapper,
            $this->loggersServiceStub,
            "dummy",
            ['cpu_count' => 2],
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
    }
}
