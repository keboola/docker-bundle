<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseContainerTest;

class ContainerUtf8SanitizationTest extends BaseContainerTest
{
    public function testStdout()
    {
        $script = [
            'import sys',
            'print("begin")',
            'sys.stdout.buffer.write(b"\x3D\xD8\x4F\xDE")',
            'print("end")',
        ];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertStringContainsString("begin\n=Oend", $process->getOutput());
    }

    public function testUserError()
    {
        $script = [
            'import sys',
            'print("begin")',
            'sys.stdout.buffer.write(b"\x3D\xD8\x4F\xDE")',
            'print("end")',
            'sys.exit(1)',
        ];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        self::expectException(UserException::class);
        self::expectExceptionMessage("begin\n=Oend");
        $container->run();
    }

    public function testLogs()
    {
        $script = [
            'import sys',
            'print("begin")',
            'sys.stdout.buffer.write(b"\x3D\xD8\x4F\xDE")',
            'print("end")',
        ];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();
        // Output may arrive in streaming chunks (each trimmed individually), so check records separately.
        // The key assertion is that the invalid UTF-8 bytes were sanitized to "=O" in the logs.
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('begin'));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('=O'));
        self::assertTrue($this->getLogHandler()->hasInfoThatContains('begin'));
        self::assertTrue($this->getLogHandler()->hasInfoThatContains('=O'));
    }
}
