<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerErrorHandlingTest extends BaseContainerTest
{
    private function getImageConfiguration()
    {
        return [
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                ],
            ],
        ];
    }

    public function testSuccess()
    {
        $script = ['print("Hello from Keboola Space Program")'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script);
        $process = $container->run();

        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Keboola Space Program", trim($process->getOutput()));
    }

    public function testFatal()
    {
        $script = ['import sys', 'print("application error")', 'sys.exit(2)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('application error', $e->getMessage());
        }
    }

    public function testGraceful()
    {
        $script = ['import sys', 'print("user error")', 'sys.exit(1)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $this->assertContains('user error', $e->getMessage());
        }
    }

    public function testLessGraceful()
    {
        $script = ['import sys', 'print("less graceful error")', 'sys.exit(255)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }

    public function testEnvironmentPassing()
    {
        $script = ['import os', 'print(os.environ["KBC_TOKENID"])'];
        $value = '123 ščř =-\'"321';
        $container = $this->getContainer($this->getImageConfiguration(), ['KBC_TOKENID' => $value], $script);

        $process = $container->run();
        $this->assertContains($value, $process->getOutput());
    }

    public function testTimeout()
    {
        $timeout = 10;
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = $timeout;
        $script = ['print("done")'];
        $container = $this->getContainer($imageConfiguration, [], $script);

        // set benchmark time
        $benchmarkStartTime = time();
        $container->run();
        $benchmarkDuration = time() - $benchmarkStartTime;

        // actual test
        $script = ['import time', 'time.sleep(20)'];
        $container = $this->getContainer($imageConfiguration, [], $script);
        $testStartTime = time();
        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $testDuration = time() - $testStartTime;
            $this->assertContains('timeout', $e->getMessage());
            // test should last longer than benchmark
            $this->assertGreaterThan($benchmarkDuration, $testDuration);
            // test shouldn't last longer than the benchmark plus process timeout (plus a safety margin)
            $this->assertLessThan($benchmarkDuration + $timeout + 5, $testDuration);
        }
    }

    public function testTimeoutMoreThanDefault()
    {
        // check that the container can run longer than the default 60s symfony process timeout
        $script = ['import time', 'time.sleep(100)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
    }

    public function testInvalidImage()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['definition']['uri'] = '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/non-existent';
        try {
            $this->getContainer($imageConfiguration, [], []);
            $this->fail("Must raise an exception for invalid image.");
        } catch (ApplicationException $e) {
            $this->assertContains('Cannot pull', $e->getMessage());
        }
    }

    public function testOutOfMemory()
    {
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory');

        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["data"]["memory"] = "32m";
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script);
        $container->run();
    }

    public function testOutOfMemoryCrippledComponent()
    {
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory');
        $imageConfiguration = [
            "data" => [
                "memory" => "32m",
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "commands" => [],
                        "entry_point" => "python /home/main.py --data=/data/ || true"
                    ],
                ]
            ]
        ];
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script);
        $container->run();
    }
}
