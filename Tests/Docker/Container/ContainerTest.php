<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Tests\BaseContainerTest;

class ContainerTest extends BaseContainerTest
{
    public function testRunCommandWithContainerRootUserFeature()
    {
        $runCommandOptions = new RunCommandOptions(
            [
                'com.keboola.runner.jobId=12345678',
                'com.keboola.runner.runId=10.20.30',
            ],
            ['var' => 'val', 'příliš' => 'žluťoučký', 'var2' => 'weird = \'"value' ],
        );
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, $runCommandOptions, [], false);

        $expected = 'sudo timeout --signal=SIGKILL 3600'
            . ' docker run'
            . " --volume '" . $this->getTempDir() . "/data:/data'"
            . " --volume '" . $this->getTempDir() . "/tmp:/tmp'"
            . " --memory '256M'"
            . " --net 'bridge'"
            . " --cpus '2'"
            . ' --env "var=val"'
            . ' --env "příliš=žluťoučký"'
            . " --env \"var2=weird = '\\\"value\""
            . " --label 'com.keboola.runner.jobId=12345678'"
            . " --label 'com.keboola.runner.runId=10.20.30'"
            . " --name 'name'"
            . " '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation:1.4.0'";
        self::assertEquals($expected, $container->getRunCommand('name'));
    }

    public function testRunCommandWithKeepaliveOverrideUserFeature()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['features'] = ['container-tcpkeepalive-60s-override'];
        $container = $this->getContainer($imageConfiguration, [], [], false);
        self::assertStringContainsString(' --sysctl net.ipv4.tcp_keepalive_time=60', $container->getRunCommand('name'));
    }

    public function testRunCommandContainerWithoutRootUserFeature()
    {
        $container = $this->getContainer($this->getImageConfiguration(), [], [], false);
        self::assertStringContainsString(' --user $(id -u):$(id -g)', $container->getRunCommand('name'));
    }

    public function testRunCommandContainerWithoutSwap()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['features'] = ['no-swap'];
        $container = $this->getContainer($imageConfiguration, [], [], false);
        self::assertStringContainsString(" --memory-swap '256M'", $container->getRunCommand('name'));
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
