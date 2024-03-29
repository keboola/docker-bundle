<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseContainerTest;

class ContainerErrorHandlingTest extends BaseContainerTest
{
    public function testSuccess()
    {
        $script = ['print("Hello from Keboola Space Program")'];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();

        self::assertEquals(0, $process->getExitCode());
        self::assertStringContainsString('Hello from Keboola Space Program', trim($process->getOutput()));
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
        self::assertStringContainsString($value, $process->getOutput());
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
            self::assertStringContainsString('timeout', $e->getMessage());
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
        $imageConfiguration['data']['definition']['uri'] =
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/non-existent';
        self::expectExceptionMessage('Cannot pull');
        self::expectException(ApplicationException::class);
        $this->getContainer($imageConfiguration, [], [], true);
    }

    public function testOutOfMemory()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['memory'] = '32m';
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory (exceeded 32M)');
        $container->run();
    }

    public function testOutOfMemoryOverride()
    {
        self::markTestSkipped('Unstable test');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['memory'] = '32m';
        $imageConfiguration['id'] = 'dummy.component';
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer(
            $imageConfiguration,
            [],
            $script,
            true,
            ['runner.dummy.component.memoryLimitMBs' => ['value' => 10]],
        );
        $this->expectException(OutOfMemoryException::class);
        $this->expectExceptionMessage('Component out of memory (exceeded 10M)');
        $container->run();
    }

    public function testOutOfMemoryCrippledComponent()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $script = ['list = []', 'for i in range(100000000):', '   list.append("0123456789")'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        self::expectException(OutOfMemoryException::class);
        self::expectExceptionMessage('Component out of memory');
        $container->run();
    }
}
