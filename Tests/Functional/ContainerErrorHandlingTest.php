<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Defuse\Crypto\Key;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerErrorHandlingTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        $this->encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
    }

    private function createScript(Temp $temp, $contents)
    {
        $temp->initRunFolder();
        $dataDir = $temp->getTmpFolder();

        $fs = new Filesystem();
        $fs->dumpFile($dataDir . DIRECTORY_SEPARATOR . 'test.php', $contents);

        return $dataDir;
    }

    private function getContainer($imageConfig, $dataDir, $envs)
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, new Component($imageConfig), new Temp(), true);
        $image->prepare([]);

        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], $envs)
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

    public function testHelloWorld()
    {
        $temp = new Temp();
        $temp->initRunFolder();
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "hello-world"
                ]
            ]
        ];

        $container = $this->getContainer($imageConfiguration, $temp->getTmpFolder(), []);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Docker", trim($process->getOutput()));
    }

    public function testSuccess()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php echo "Hello from Keboola Space Program";');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);
        $process = $container->run();

        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Keboola Space Program", trim($process->getOutput()));
    }

    public function testFatal()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php this would be a parse error');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('Parse error', $e->getMessage());
        }
    }

    public function testGraceful()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php echo "graceful error"; exit(1);');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }


    public function testLessGraceful()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php echo "less graceful error"; exit(255);');
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, []);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }

    public function testEnvironmentPassing()
    {
        $temp = new Temp('docker');
        $dataDir = $this->createScript($temp, '<?php echo getenv("KBC_TOKENID");');
        $value = '123 ščř =-\'"321';
        $container = $this->getContainer($this->getImageConfiguration(), $dataDir, ['KBC_TOKENID' => $value]);

        $process = $container->run();
        $this->assertEquals($value, $process->getOutput());
    }

    public function testTimeout()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = 10;
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $dataDir = $this->createScript($temp, '<?php echo "done";');
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], [])
        );

        // set benchmark time
        $benchmarkStartTime = time();
        $container->run();
        $benchmarkDuration = time() - $benchmarkStartTime;

        // actual test
        $dataDir = $this->createScript($temp, '<?php sleep(20);');
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], [])
        );
        $testStartTime = time();
        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $testDuration = time() - $testStartTime;
            $this->assertContains('timeout', $e->getMessage());
            // test should last longer than benchmark
            $this->assertGreaterThan($benchmarkDuration, $testDuration);
            // test shouldn't last longer than benchmark plus process timeout (plus a safety margin)
            $this->assertLessThan(
                $benchmarkDuration + $image->getSourceComponent()->getProcessTimeout() + 5,
                $testDuration
            );
        }
    }

    public function testTimeoutMoreThanDefault()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $dataDir = $this->createScript($temp, '<?php sleep(100);');
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], [])
        );
        $container->run();
    }

    public function testInvalidImage()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/non-existent"
                ]
            ]
        ];

        $dataDir = $this->createScript($temp, '<?php sleep(10);');
        try {
            $this->getContainer($imageConfiguration, $dataDir, []);
            $this->fail("Must raise an exception for invalid image.");
        } catch (ApplicationException $e) {
            $this->assertContains('Cannot pull', $e->getMessage());
        }
    }

    public function testOutOfMemory()
    {
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory');

        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["data"]["memory"] = "32m";

        $dataDir = $this->createScript(
            $temp,
            '<?php
            $array = [];
            for($i = 0; $i < 1000000; $i++) {
                $array[] = "0123456789";
            }
            print "finished";'
        );
        $container = $this->getContainer($imageConfiguration, $dataDir, []);
        $container->run();
    }

    public function testOutOfMemoryCrippledComponent()
    {
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory');

        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["data"]["memory"] = "32m";
        $imageConfiguration["data"]["definition"]["build_options"]["entry_point"] =
            "php /data/test.php || true";

        $dataDir = $this->createScript(
            $temp,
            '<?php
            $array = [];
            for($i = 0; $i < 1000000; $i++) {
                $array[] = "0123456789";
            }
            print "finished";'
        );
        $container = $this->getContainer($imageConfiguration, $dataDir, []);
        $container->run();
    }
}
