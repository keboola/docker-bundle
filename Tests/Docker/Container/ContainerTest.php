<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Symfony\Component\Process\Process;

class ContainerTest extends BaseContainerTest
{
    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
    private const IMAGE_ID = '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation:1.4.0';

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
            . " --env 'var=val'"
            . " --env 'příliš=žluťoučký'"
            . " --env 'var2=weird = '\\''\"value'"
            . " --label 'com.keboola.runner.jobId=12345678'"
            . " --label 'com.keboola.runner.runId=10.20.30'"
            . " --name 'name'"
            . " '" . self::IMAGE_ID . "'";
        self::assertEquals($expected, $container->getRunCommand('name'));
    }

    /**
     * @dataProvider provideShellInjectionEnvironmentVariables
     */
    public function testRunCommandEscapesEnvironmentVariables(string $value, string $expectedArgument): void
    {
        $container = $this->getContainerWithEnvironmentVariables(['KBC_CONFIGID' => $value]);

        self::assertStringContainsString(
            ' --env ' . $expectedArgument . " --name 'name'",
            $container->getRunCommand('name'),
        );
    }

    /**
     * The run command is handed to `/bin/sh -c` via Process::fromShellCommandline(), so the only thing that
     * matters is what the shell parses it into: every environment variable must come out as a single argument
     * holding the original value verbatim, with nothing substituted and no extra command appended.
     *
     * @dataProvider provideShellInjectionEnvironmentVariables
     */
    public function testEnvironmentVariablesReachTheShellVerbatim(string $value): void
    {
        $container = $this->getContainerWithEnvironmentVariables(['KBC_CONFIGID' => $value]);

        self::assertSame(
            [
                '--volume', $this->getTempDir() . '/data:/data',
                '--volume', $this->getTempDir() . '/tmp:/tmp',
                '--memory', '256M',
                '--net', 'bridge',
                '--cpus', '2',
                '--env', 'KBC_CONFIGID=' . $value,
                '--name', 'name',
                self::IMAGE_ID,
            ],
            $this->parseCommandArguments($container->getRunCommand('name')),
        );
    }

    public function provideShellInjectionEnvironmentVariables(): iterable
    {
        yield 'command substitution' => [
            'value' => 'x$(id -un)y',
            'expectedArgument' => "'KBC_CONFIGID=x\$(id -un)y'",
        ];
        yield 'backticks' => [
            'value' => 'x`id -un`y',
            'expectedArgument' => "'KBC_CONFIGID=x`id -un`y'",
        ];
        yield 'escaped quote breaking out of the double-quoted string' => [
            'value' => 'a\";id;#',
            'expectedArgument' => "'KBC_CONFIGID=a\\\";id;#'",
        ];
        yield 'trailing backslash' => [
            'value' => 'a\\',
            'expectedArgument' => "'KBC_CONFIGID=a\\'",
        ];
        yield 'single quote' => [
            'value' => "a';id;#",
            'expectedArgument' => "'KBC_CONFIGID=a'\\'';id;#'",
        ];
        yield 'newline' => [
            'value' => "a\nid",
            'expectedArgument' => "'KBC_CONFIGID=a\nid'",
        ];
        yield 'variable expansion' => [
            'value' => 'a$HOME',
            'expectedArgument' => "'KBC_CONFIGID=a\$HOME'",
        ];
    }

    private function getContainerWithEnvironmentVariables(array $environmentVariables): Container
    {
        $imageConfiguration = $this->getImageConfiguration();
        // avoids the intentional `--user $(id -u):$(id -g)` substitution, so the whole command line can be
        // asserted as literal words
        $imageConfiguration['features'] = ['container-root-user'];

        return $this->getContainer(
            $imageConfiguration,
            new RunCommandOptions([], $environmentVariables),
            [],
            false,
        );
    }

    /**
     * Lets `/bin/sh` split the `docker run` arguments the same way it does in production and returns the
     * resulting argument list.
     *
     * @return string[]
     */
    private function parseCommandArguments(string $runCommand): array
    {
        $dockerRunPosition = strpos($runCommand, ' docker run');
        self::assertNotFalse($dockerRunPosition);
        $arguments = substr($runCommand, $dockerRunPosition + strlen(' docker run'));

        $process = Process::fromShellCommandline('printf \'%s\0\'' . $arguments);
        $process->mustRun();

        return explode("\0", rtrim($process->getOutput(), "\0"));
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
