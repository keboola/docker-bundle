<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseContainerTest;

class NetworkTest extends BaseContainerTest
{
    public function testNetworkBridge()
    {
        $script = [
            'import sys',
            'from subprocess import call',
            'sys.exit(call(["ping", "-W", "10", "-c", "1", "www.example.com"]))',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['network'] = 'bridge';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertStringContainsString('64 bytes from', $process->getOutput());
    }

    public function testNetworkNone()
    {
        $script = [
            'from subprocess import call',
            'import sys',
            'ret = call(["ping", "-W", "10", "-c", "1", "www.example.com"])',
            'sys.exit(ret >= 1 if 1 else 0)',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['network'] = 'none';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Ping must fail');
        } catch (UserException $e) {
            self::assertStringContainsString(
                'ping: www.example.com: Temporary failure in name resolution',
                $e->getMessage(),
            );
        }
    }
}
