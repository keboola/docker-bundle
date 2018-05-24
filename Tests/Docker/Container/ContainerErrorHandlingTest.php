<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;

class ContainerErrorHandlingTest extends BaseContainerTest
{
    private function getImageConfiguration()
    {
        return [
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
            ],
        ];
    }

    public function testSuccess()
    {
        $script = ['print("Hello from Keboola Space Program")'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();

        self::assertEquals(0, $process->getExitCode());
        self::assertContains('Hello from Keboola Space Program', trim($process->getOutput()));
    }

    public function testFatal()
    {
        $script = ['import sys', 'print("application error")', 'sys.exit(2)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('application error');
        $container->run();
    }

    public function testGraceful()
    {
        $script = ['import sys', 'print("user error")', 'sys.exit(1)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        
        self::expectException(UserException::class);
        self::expectExceptionMessage('user error');
        $container->run();
    }

    public function testLessGraceful()
    {
        $script = ['import sys', 'print("less graceful error")', 'sys.exit(255)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);

        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('graceful error');
        $container->run();
    }

    public function testEnvironmentPassing()
    {
        $script = ['import os', 'print(os.environ["KBC_TOKENID"])'];
        $value = '123 ščř =-\'"321';
        $commandOptions = new RunCommandOptions([], ['KBC_TOKENID' => $value]);
        $container = $this->getContainer($this->getImageConfiguration(), $commandOptions, $script, true);

        $process = $container->run();
        self::assertContains($value, $process->getOutput());
    }

    public function testTimeout()
    {
        $timeout = 10;
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = $timeout;
        $script = ['print("done")'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);

        // set benchmark time
        $benchmarkStartTime = time();
        $container->run();
        $benchmarkDuration = time() - $benchmarkStartTime;

        // actual test
        $script = ['import time', 'time.sleep(20)'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $testStartTime = time();
        try {
            $container->run();
            self::fail('Must raise an exception');
        } catch (UserException $e) {
            $testDuration = time() - $testStartTime;
            self::assertContains('timeout', $e->getMessage());
            // test should last longer than benchmark
            self::assertGreaterThan($benchmarkDuration, $testDuration);
            // test shouldn't last longer than the benchmark plus process timeout (plus a safety margin)
            self::assertLessThan($benchmarkDuration + $timeout + 5, $testDuration);
        }
    }

    public function testTimeoutMoreThanDefault()
    {
        // check that the container can run longer than the default 60s symfony process timeout
        $script = ['import time', 'time.sleep(80)'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
    }

    public function testInvalidImage()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['definition']['uri'] = '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/non-existent';
        self::expectExceptionMessage('Cannot pull');
        self::expectException(ApplicationException::class);
        $this->getContainer($imageConfiguration, [], [], true);
    }

    public function testOutOfMemory()
    {
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory');

        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['memory'] = '32m';
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();
    }

    public function testOutOfMemoryCrippledComponent()
    {
        $imageConfiguration = [
            'data' => [
                'memory' => '32m',
                'definition' => [
                    'type' => 'builder',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'aws-ecr',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git', // not used, can be anything
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'python /home/main.py --data=/data/ || true'
                    ],
                ]
            ]
        ];
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        self::expectException(OutOfMemoryException::class);
        self::expectExceptionMessage('Component out of memory');
        $container->run();
    }
}
