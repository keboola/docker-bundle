<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Container\Options;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class ContainerErrorHandlingTest extends \PHPUnit_Framework_TestCase
{
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
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $log->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, new Component($imageConfig), new Temp(), true);
        $image->prepare([]);

        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            $envs,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new Options([])
        );
        return $container;
    }

    private function getImageConfiguration()
    {
        return [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-base-php56:0.0.2",
                    "build_options" => [
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
        $imageConfiguration['data']['process_timeout'] = 5;
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $dataDir = $this->createScript($temp, '<?php echo "done";');
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            [],
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new Options([])
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
            [],
            'not-used',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new Options([])
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
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $dataDir = $this->createScript($temp, '<?php sleep(100);');
        $container = new Container(
            'container-error-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            [],
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new Options([])
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
        $this->expectException(\Keboola\DockerBundle\Exception\OutOfMemoryException::class);
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
}
