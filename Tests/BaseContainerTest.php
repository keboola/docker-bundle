<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use function GuzzleHttp\json_encode;

abstract class BaseContainerTest extends TestCase
{
    private Temp $temp;
    private TestHandler $testHandler;
    private TestHandler $containerTestHandler;

    /** @var null|callable */
    private $createEventCallback;

    private ?LoggersService $logService;
    private array $componentConfig;
    private Client $storageClientStub;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . getenv('AWS_ECR_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('AWS_ECR_SECRET_ACCESS_KEY'));
        $this->temp = new Temp('runner-tests');
        $this->createEventCallback = null;
        $this->logService = null;
        $this->componentConfig = [];
    }

    protected function getImageConfiguration()
    {
        return [
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => '1.4.0',
                ],
                'image_parameters' => [
                    '#secure' => 'secure',
                    'not-secure' => [
                        'this' => 'public',
                        '#andthis' => 'isAlsoSecure',
                    ],
                ],
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

    protected function getStorageClientStub()
    {
        return $this->storageClientStub;
    }

    protected function getContainer(array $imageConfig, $commandOptions, array $contents, $prepare, $projectLimits = [])
    {
        $this->createScript($contents);
        $this->testHandler = new TestHandler();
        $this->containerTestHandler = new TestHandler();

        if (!$this->createEventCallback) {
            $this->createEventCallback = function (/** @noinspection PhpUnusedParameterInspection */Event $event) {
                return true;
            };
        }
        $this->storageClientStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storageClientStub->expects($this->any())
            ->method('createEvent')
            ->with($this->callback($this->createEventCallback));

        $sapiHandler = new StorageApiHandler('runner-tests', $this->getStorageClientStub());
        $log = new Logger('runner-tests', [$this->testHandler]);
        $containerLog = new ContainerLogger('container-tests', [$this->containerTestHandler]);
        $this->logService = new LoggersService($log, $containerLog, $sapiHandler);
        $image = ImageFactory::getImage(
            $log,
            new ComponentSpecification($imageConfig),
            true,
        );
        if ($prepare) {
            $image->setRetryLimits(100, 100, 1);
            $image->prepare($this->componentConfig);
        }
        $this->logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        if (!$commandOptions) {
            $commandOptions = new RunCommandOptions([], []);
        }
        $outputFilter = new OutputFilter(10000);
        $outputFilter->collectValues([$imageConfig]);
        return new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $this->temp->getTmpFolder() . '/data',
            $this->temp->getTmpFolder() . '/tmp',
            (string) getenv('RUNNER_COMMAND_TO_GET_HOST_IP'),
            (int) getenv('RUNNER_MIN_LOG_PORT'),
            (int) getenv('RUNNER_MAX_LOG_PORT'),
            $commandOptions,
            $outputFilter,
            new Limits($log, ['cpu_count' => 2], $projectLimits, [], null),
        );
    }

    private function createScript(array $contents)
    {
        $fs = new Filesystem();
        $configFile['parameters']['script'] = $contents;
        $fs->dumpFile($this->temp->getTmpFolder() . '/data/config.json', json_encode($configFile));
    }
}
