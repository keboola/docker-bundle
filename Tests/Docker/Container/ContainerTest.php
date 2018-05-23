<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerTest extends BaseContainerTest
{
    public function testRunCommandWithContainerRootUserFeature()
    {
        $imageConfiguration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo-app",
                    "tag" => "master"
                ]
            ],
            "features" => [
                "container-root-user"
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
            '/data',
            '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ], $envs),
            new OutputFilter(),
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );

        // block devices
        $process = new \Symfony\Component\Process\Process("lsblk --nodeps --output NAME --noheadings 2>/dev/null");
        $process->mustRun();
        $devices = array_filter(explode("\n", $process->getOutput()), function ($device) {
            return !empty($device);
        });
        $deviceLimits = "";
        foreach ($devices as $device) {
            $deviceLimits .= " --device-write-bps '/dev/{$device}:50m'";
            $deviceLimits .= " --device-read-bps '/dev/{$device}:50m'";
        }

        $expected = "sudo timeout --signal=SIGKILL 3600"
            . " docker run"
            . " --volume '/data:/data'"
            . " --volume '/tmp:/tmp'"
            . " --memory '256m'"
            . " --memory-swap '256m'"
            . " --net 'bridge'"
            . " --cpus '2'"
            . $deviceLimits
            . " --env \"var=val\""
            . " --env \"příliš=žluťoučký\""
            . " --env \"var2=weird = '\\\"value\""
            . " --label 'com.keboola.runner.jobId=12345678'"
            . " --label 'com.keboola.runner.runId=10.20.30'"
            . " --name 'name'"
            . " 'keboola/docker-demo-app:master'";
        $this->assertEquals($expected, $container->getRunCommand("name"));
    }

    public function testRunCommandContainerWithoutRootUserFeature()
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
            '/data',
            '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ], $envs),
            new OutputFilter(),
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );

        $this->assertContains(" --user \$(id -u):\$(id -g)", $container->getRunCommand("name"));
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
            $temp->getTmpFolder(),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            new OutputFilter(),
            new Limits($log, [], [], [], [])
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
            $temp->getTmpFolder(),
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            new OutputFilter(),
            new Limits($log, [], [], [], [])
        );
        $expected = "sudo docker rm -f 'name'";
        $this->assertEquals($expected, $container->getRemoveCommand("name"));
    }
}
