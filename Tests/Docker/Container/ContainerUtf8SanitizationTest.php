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
