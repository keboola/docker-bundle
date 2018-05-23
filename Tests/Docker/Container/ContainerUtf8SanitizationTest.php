<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Keboola\DockerBundle\Docker\RunCommandOptions;

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

        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("begin\n=Oend", $process->getOutput());
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
        try {
            $container->run();
        } catch (UserException $e) {
            $this->assertContains("begin\n=Oend", $e->getMessage());
        }
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
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains("begin\n=Oend"));
        $this->assertFalse($this->getLogHandler()->hasInfoThatContains("begin\n=Oend"));
    }
}
