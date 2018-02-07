<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->encryptorFactory->setComponentId('docker-dummy-component');
    }

    public function testRun()
    {
        $imageConfiguration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo-app"
                ]
            ]
        ]);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, $imageConfiguration, new Temp(), true);
        $temp = new Temp();
        $fs = new Filesystem();
        $dataDir = $temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'data';
        $fs->mkdir($dataDir);
        $tableDir = $dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'tables' . DIRECTORY_SEPARATOR;
        $fs->mkdir($tableDir);
        $container = new Docker\Mock\Container(
            'docker-container-test',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            new OutputFilter([])
        );

        $callback = function () {
            $process = new Process('echo "Processed 2 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);
        $configFile = <<< EOT
{
    "storage": {
        "input": {
            "tables": {
                "0": {
                    "source": "in.c-main.data"
                }
            }
        },
        "output": {
            "tables": {
                "0": {
                    "source": "sliced.csv",
                    "destination": "out.c-main.data"
                }
            }
        }
    },
    "parameters": {
        "primary_key_column": "id",
        "data_column": "text",
        "string_length": 10
    }
}
EOT;
        file_put_contents($dataDir . DIRECTORY_SEPARATOR . "config.json", $configFile);

        $dataFile = <<< EOF
id,text,some_other_column
1,"Short text","Whatever"
2,"Long text Long text Long text","Something else"
EOF;

        file_put_contents($tableDir . DIRECTORY_SEPARATOR . 'in.c-main.data.csv', $dataFile);

        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Processed 2 rows.", trim($process->getOutput()));
    }

    public function testRunCommand()
    {
        $imageConfiguration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo-app",
                    "tag" => "master"
                ]
            ]
        ]);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, $imageConfiguration, new Temp(), true);
        $envs = ["var" => "val", "příliš" => 'žluťoučký', "var2" => "weird = '\"value" ];
        $container = new Container(
            'docker-container-test',
            $image,
            $log,
            $containerLog,
            '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ], $envs),
            new OutputFilter([])
        );
        $expected = "sudo timeout --signal=SIGKILL 3600"
            . " docker run"
            . " --volume '/tmp:/data'"
            . " --memory '64m'"
            . " --memory-swap '64m'"
            . " --cpu-shares '1024'"
            . " --net 'bridge'"
            . " --env \"var=val\""
            . " --env \"příliš=žluťoučký\""
            . " --env \"var2=weird = '\\\"value\""
            . " --label 'com.keboola.runner.jobId=12345678'"
            . " --label 'com.keboola.runner.runId=10.20.30'"
            . " --name 'name'"
            . " 'keboola/docker-demo-app:master'";
        $this->assertEquals($expected, $container->getRunCommand("name"));
    }

    public function testInspectCommand()
    {
        $imageConfiguration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo-app"
                ]
            ]
        ]);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, $imageConfiguration, new Temp(), true);
        $temp = new Temp();
        $container = new Container(
            'docker-container-test',
            $image,
            $log,
            $containerLog,
            $temp->getTmpFolder(),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            new OutputFilter([])
        );
        $expected = "sudo docker inspect 'name'";
        $this->assertEquals($expected, $container->getInspectCommand("name"));
    }

    public function testRemoveCommand()
    {
        $imageConfiguration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo-app"
                ]
            ]
        ]);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), $log, $imageConfiguration, new Temp(), true);
        $temp = new Temp();
        $container = new Container(
            'docker-container-test',
            $image,
            $log,
            $containerLog,
            $temp->getTmpFolder(),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            new OutputFilter([])
        );
        $expected = "sudo docker rm -f 'name'";
        $this->assertEquals($expected, $container->getRemoveCommand("name"));
    }
}
