<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerUtf8SanitizationTest extends \PHPUnit_Framework_TestCase
{
    private function createScript(Temp $temp, $contents)
    {
        $temp->initRunFolder();
        $dataDir = $temp->getTmpFolder();

        $fs = new Filesystem();
        $fs->dumpFile($dataDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'test.php', $contents);

        return $dataDir;
    }

    private function getContainer($imageConfig, $dataDir, $envs, Logger $log = null, ContainerLogger $containerLog = null)
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        if (!$log) {
            $log = new Logger("null");
            $log->pushHandler(new NullHandler());
        }
        if (!$containerLog) {
            $containerLog = new ContainerLogger("null");
            $containerLog->pushHandler(new NullHandler());
        }
        $image = ImageFactory::getImage($encryptorFactory->getEncryptor(), $log, new Component($imageConfig), new Temp(), true);
        $image->prepare([]);

        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir . '/data',
            $dataDir . '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], $envs),
            new OutputFilter(),
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );
        return $container;
    }

    private function getImageConfiguration()
    {
        return [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/docker-demo-app",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "quayio",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "commands" => [],
                        "entry_point" => "php /data/test.php"
                    ],
                ]
            ]
        ];
    }

    public function testStdout()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php echo substr("ěš", 0, 3);');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);
        $process = $container->run();

        $this->assertEquals(0, $process->getExitCode());
        $this->assertEquals("ě", $process->getOutput());
    }

    public function testUserError()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php  echo substr("ěš", 0, 3); exit(1);');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);
        try {
            $container->run();
        } catch (UserException $e) {
            $this->assertEquals("ě", $e->getMessage());
        }
    }

    public function testLogs()
    {
        $log = new Logger("test");
        $logTestHandler = new TestHandler();
        $log->pushHandler($logTestHandler);
        $containerLog = new ContainerLogger("test");
        $containerLogTestHandler = new TestHandler();
        $containerLog->pushHandler($containerLogTestHandler);

        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php  echo substr("ěš", 0, 3); ');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, [], $log, $containerLog);
        $container->run();
        $containerLogTestHandler->hasInfoThatContains("ě");
        $logTestHandler->hasInfoThatContains("ě");
    }
}
