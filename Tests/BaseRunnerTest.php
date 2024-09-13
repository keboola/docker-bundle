<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListComponentsOptions;
use Keboola\StorageApiBranch\Branch;
use Keboola\StorageApiBranch\ClientWrapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class BaseRunnerTest extends TestCase
{
    use TestEnvVarsTrait;

    private TestHandler $containerHandler;
    private TestHandler $runnerHandler;
    protected ObjectEncryptor $encryptor;
    private string $projectId;
    protected Client $client;

    /**
     * @var null|(Client&MockObject)
     */
    protected $clientMock;

    /**
     * @var LoggersService&MockObject
     */
    protected $loggersServiceStub;

    protected function initStorageClient(): void
    {
        $this->client = new BranchAwareClient(
            'default',
            [
                'url' => self::getOptionalEnv('STORAGE_API_URL'),
                'token' => self::getOptionalEnv('STORAGE_API_TOKEN'),
            ],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . self::getOptionalEnv('AWS_ECR_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . self::getOptionalEnv('AWS_ECR_SECRET_ACCESS_KEY'));

        $stackId = parse_url(self::getRequiredEnv('STORAGE_API_URL'), PHP_URL_HOST);
        self::assertNotEmpty($stackId);

        $this->encryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            $stackId,
            self::getRequiredEnv('AWS_KMS_TEST_KEY'),
            self::getRequiredEnv('AWS_ECR_REGISTRY_REGION'),
            null,
            null,
        ));

        $this->initStorageClient();
        $tokenInfo = $this->client->verifyToken();
        print(sprintf(
            'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.',
            $tokenInfo['description'],
            $tokenInfo['id'],
            $tokenInfo['owner']['name'],
            $tokenInfo['owner']['id'],
            $this->client->getApiUrl(),
        ));

        $this->projectId = (string) $tokenInfo['owner']['id'];
        $this->containerHandler = new TestHandler();
        $this->runnerHandler = new TestHandler();
        $log = new Logger('test-logger', [$this->runnerHandler]);
        $containerLogger = new ContainerLogger('test-container-logger', [$this->containerHandler]);
        $this->loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggersServiceStub->expects(self::any())
            ->method('getLog')
            ->will($this->returnValue($log));
        $this->loggersServiceStub->expects(self::any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger));
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    protected function getEncryptor(): ObjectEncryptor
    {
        return $this->encryptor;
    }

    protected function getRunnerHandler(): TestHandler
    {
        return $this->runnerHandler;
    }

    protected function getContainerHandler(): TestHandler
    {
        return $this->containerHandler;
    }

    /**
     * @return LoggersService&MockObject
     */
    protected function getLoggersService()
    {
        return $this->loggersServiceStub;
    }

    protected function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param Client&MockObject $clientMock
     */
    protected function setClientMock($clientMock): void
    {
        $this->clientMock = $clientMock;
    }

    protected function getRunner(): Runner
    {
        if ($this->clientMock) {
            $storageClientStub = $this->clientMock;
        } else {
            $storageClientStub = $this->client;
        }

        $defaultBranchId = null;
        $basicClient = new Client(
            [
                'url' => self::getOptionalEnv('STORAGE_API_URL'),
                'token' => self::getOptionalEnv('STORAGE_API_TOKEN'),
            ],
        );
        $devBranches = new DevBranches($basicClient);
        foreach ($devBranches->listBranches() as $branch) {
            if ($branch['isDefault']) {
                $defaultBranchId = $branch['id'];
                break;
            }
        }

        $clientWrapper = $this->createMock(ClientWrapper::class);
        // basicClient TODO: should be removed after https://keboola.atlassian.net/browse/SOX-368
        $clientWrapper->method('getBasicClient')->willReturn($basicClient);
        $clientWrapper->method('getBranchClient')->willReturn($storageClientStub);
        $clientWrapper->method('getTableAndFileStorageClient')->willReturn($basicClient);
        $clientWrapper->method('getClientForBranch')->willReturn($storageClientStub);
        $clientWrapper->method('getDefaultBranch')->willReturn(
            new Branch((string) $defaultBranchId, 'default branch', true, null),
        );
        $clientWrapper->method('getBranchId')->willReturn((string) $defaultBranchId);
        return new Runner(
            $this->encryptor,
            $clientWrapper,
            $this->loggersServiceStub,
            new OutputFilter(10000),
            ['cpu_count' => 2],
            (int) self::getOptionalEnv('RUNNER_MIN_LOG_PORT'),
        );
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
}
