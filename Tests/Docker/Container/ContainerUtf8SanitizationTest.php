<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\Syrup\Exception\UserException;

class ContainerUtf8SanitizationTest extends BaseContainerTest
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
        self::assertContains("begin\n=Oend", $process->getOutput());
    }

    public function testUserError()
    {
        $script = [
            'import sys',
            'print("begin")',
            'sys.stdout.buffer.write(b"\x3D\xD8\x4F\xDE")',
            'print("end")',
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
        self::assertFalse($this->getLogHandler()->hasInfoThatContains("begin\n=Oend"));
    }
}
