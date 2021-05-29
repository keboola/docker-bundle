<?php

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
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains("begin\n=Oend"));
        self::assertTrue($this->getLogHandler()->hasInfoThatContains("begin\n=Oend"));
    }
}
