<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class BaseContainerTest extends TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var TestHandler
     */
    private $testHandler;

    /**
     * @var TestHandler
     */
    private $containerTestHandler;

    /**
     * @var callable
     */
    private $createEventCallback;

    /**
     * @var LoggersService
     */
    private $logService;

    /**
     * @var StorageApiService
     */
    private $storageServiceStub;

    /**
     * @var array
     */
    private $componentConfig;

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->temp = new Temp('runner-tests');
        $this->temp->initRunFolder();
        $this->createEventCallback = null;
        $this->logService = null;
        $this->storageServiceStub = null;
        $this->componentConfig = [];
    }

    protected function getImageConfiguration()
    {
        return [
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
                'image_parameters' => [
                    '#secure' => 'secure',
                    'not-secure' => [
                        'this' => 'public',
                        '#andthis' => 'isAlsoSecure',
                    ]
                ]
            ],
        ];
    }

    protected function getTempDir()
    {
        return $this->temp->getTmpFolder();
    }

    protected function getContainerLogHandler()
    {
        return $this->containerTestHandler;
    }

    protected function getLogHandler()
    {
        return $this->testHandler;
    }

    protected function setCreateEventCallback($createEventCallback)
    {
        $this->createEventCallback = $createEventCallback;
    }

    protected function setComponentConfig(array $configData)
    {
        $this->componentConfig = $configData;
    }

    protected function getLoggersService()
    {
        return $this->logService;
    }

    protected function getStorageApiService()
    {
        return $this->storageServiceStub;
    }

    protected function getContainer(array $imageConfig, $commandOptions, array $contents, $prepare)
    {
        $this->createScript($contents);
        $this->testHandler = new TestHandler();
        $this->containerTestHandler = new TestHandler();

        if (!$this->createEventCallback) {
            $this->createEventCallback = function (/** @noinspection PhpUnusedParameterInspection */
                Event $event) {
                return true;
            };
        }
        $storageClientStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub->expects($this->any())
            ->method('createEvent')
            ->with($this->callback($this->createEventCallback));
        $this->storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($storageClientStub));
        /** @var StorageApiService $storageServiceStub */
        $sapiHandler = new StorageApiHandler('runner-tests', $this->storageServiceStub);
        $log = new Logger('runner-tests', [$this->testHandler]);
        $containerLog = new ContainerLogger('container-tests', [$this->containerTestHandler]);
        $this->logService = new LoggersService($log, $containerLog, $sapiHandler);
        $image = ImageFactory::getImage(
            $this->encryptorFactory->getEncryptor(),
            $log,
            new Component($imageConfig),
            $this->temp,
            true
        );
        if ($prepare) {
            $image->prepare($this->componentConfig);
        }
        $this->logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        if (!$commandOptions) {
            $commandOptions = new RunCommandOptions([], []);
        }
        $outputFilter = new OutputFilter();
        $outputFilter->collectValues([$imageConfig]);
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $this->temp->getTmpFolder() . '/data',
            $this->temp->getTmpFolder() . '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            $commandOptions,
            $outputFilter,
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );
        return $container;
    }

    private function createScript(array $contents)
    {
        $fs = new Filesystem();
        $configFile['parameters']['script'] = $contents;
        $fs->dumpFile($this->temp->getTmpFolder() . '/data/config.json', \GuzzleHttp\json_encode($configFile));
    }
}
