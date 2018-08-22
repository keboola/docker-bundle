<?php

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Tests\BaseContainerTest;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class ContainerTest extends BaseContainerTest
{
    public function testRunCommandWithContainerRootUserFeature()
    {
        $runCommandOptions = new RunCommandOptions(
            [
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ],
            ['var' => 'val', 'příliš' => 'žluťoučký', 'var2' => 'weird = \'"value' ]
        );
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, $runCommandOptions, [], false);

        // block devices
        $process = new Process('lsblk --nodeps --output NAME --noheadings 2>/dev/null');
        $process->mustRun();
        $devices = array_filter(explode("\n", $process->getOutput()), function ($device) {
            return !empty($device);
        });
        $deviceLimits = '';
        foreach ($devices as $device) {
            $deviceLimits .= " --device-write-bps '/dev/{$device}:50m'";
            $deviceLimits .= " --device-read-bps '/dev/{$device}:50m'";
        }

        $expected = "sudo timeout --signal=SIGKILL 3600"
            . " docker run"
            . " --volume '" . $this->getTempDir() . "/data:/data'"
            . " --volume '" . $this->getTempDir() . "/tmp:/tmp'"
            . " --memory '256m'"
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
        self::assertEquals($expected, $container->getRunCommand('name'));
    }

    public function testRunCommandContainerWithoutRootUserFeature()
    {
        $container = $this->getContainer($this->getImageConfiguration(), [], [], false);
        self::assertContains(" --user \$(id -u):\$(id -g)", $container->getRunCommand("name"));
    }

    public function testRunCommandContainerWithoutSwap()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['features'] = ['no-swap'];
        $container = $this->getContainer($imageConfiguration, [], [], false);
        self::assertContains(" --memory-swap '256m'", $container->getRunCommand("name"));
    }

    public function testInspectCommand()
    {
        $container = $this->getContainer($this->getImageConfiguration(), null, [], false);
        $expected = "sudo docker inspect 'name'";
        self::assertEquals($expected, $container->getInspectCommand('name'));
    }

    public function testRemoveCommand()
    {
        $container = $this->getContainer($this->getImageConfiguration(), null, [], false);
        $expected = "sudo docker rm -f 'name'";
        self::assertEquals($expected, $container->getRemoveCommand('name'));
    }
}
