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

    public function testRunCommand()
    {
        $runCommandOptions = new RunCommandOptions(
            [
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ],
            ["var" => "val", "příliš" => 'žluťoučký', "var2" => "weird = '\"value" ]
        );
        $container = $this->getContainer($this->getImageConfiguration(), $runCommandOptions, []);

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
            . " --volume '" . $this->getTempDir() . "/data:/data'"
            . " --volume '" . $this->getTempDir() . "/tmp:/tmp'"
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
            . " '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation:latest'";
        $this->assertEquals($expected, $container->getRunCommand("name"));
    }

    public function testInspectCommand()
    {
        $container = $this->getContainer($this->getImageConfiguration(), null, []);
        $expected = "sudo docker inspect 'name'";
        $this->assertEquals($expected, $container->getInspectCommand("name"));
    }

    public function testRemoveCommand()
    {
        $container = $this->getContainer($this->getImageConfiguration(), null, []);
        $expected = "sudo docker rm -f 'name'";
        $this->assertEquals($expected, $container->getRemoveCommand("name"));
    }
}
