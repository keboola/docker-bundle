<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
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
    }

    private function createScript(array $contents)
    {
        $fs = new Filesystem();
        $configFile['parameters']['script'] = $contents;
        $fs->dumpFile($this->temp->getTmpFolder() . '/data/config.json', \GuzzleHttp\json_encode($configFile));
    }

    public function getContainer(array $imageConfig, array $environmentVariables, array $contents)
    {
        $this->createScript($contents);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $image = ImageFactory::getImage(
            $this->encryptorFactory->getEncryptor(),
            $log,
            new Component($imageConfig),
            $this->temp,
            true
        );
        $image->prepare([]);

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
            new RunCommandOptions([], $environmentVariables),
            new OutputFilter(),
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );
        return $container;
    }
}