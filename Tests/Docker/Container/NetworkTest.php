<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class NetworkTest extends BaseContainerTest
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
                'image_parameters' => [
                    '#secure' => 'secure',
                    'not-secure' => [
                        'this' => 'public',
                        '#andthis' => 'isAlsoSecure',
                    ]
                ]
            ],
        ];
    }

    private function getBuilderImageConfiguration()
    {
        return [
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'aws-ecr',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app', // not used, can by anything
                            'type' => 'git',
                        ],
                        'entry_point' => 'ping -W 10 -c 1 www.example.com',
                        'parameters' => [
                            [
                                'name' => 'network',
                                'type' => 'string',
                                'required' => false
                            ]
                        ]
                    ]
                ],
                'network' => 'bridge'
            ]
        ];
    }

    public function testNetworkBridge()
    {
        $script = [
            'from subprocess import call',
            'sys.exit(call(["ping", "-W", "10", "-c", "1", "www.example.com"]))',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['network'] = 'bridge';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertContains('64 bytes from', $process->getOutput());
    }

    public function testNetworkNone()
    {
        $script = [
            'from subprocess import call',
            'import sys',
            'sys.exit(call(["ping", "-W", "10", "-c", "1", "www.example.com"]))',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['network'] = 'none';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Ping must fail');
        } catch (UserException $e) {
            self::assertContains('ping: unknown host', $e->getMessage());
        }
    }

    public function testNetworkBridgeOverride()
    {
        $this->setComponentConfig(['runtime' => ['network' => 'none']]);
        $container = $this->getContainer($this->getBuilderImageConfiguration(), [], [], true);
        try {
            $container->run();
            self::fail('Ping must fail');
        } catch (UserException $e) {
            self::assertContains('ping: unknown host', $e->getMessage());
        }
    }

    public function testNetworkBridgeOverrideNoValue()
    {
        $this->setComponentConfig(['runtime' => []]);
        $container = $this->getContainer($this->getBuilderImageConfiguration(), [], [], true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertContains('64 bytes from', $process->getOutput());
    }

    public function testNetworkBridgeOverrideFail()
    {
        $imageConfiguration = $this->getBuilderImageConfiguration();
        $imageConfiguration['data']['definition']['build_options']['parameters'] = [];
        $this->setComponentConfig(['runtime' => ['network' => 'none']]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        // parameter is not defined in image, must be ignored
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertContains('64 bytes from', $process->getOutput());
    }

    public function testNetworkNoneOverride()
    {
        $imageConfiguration = $this->getBuilderImageConfiguration();
        $imageConfiguration['data']['network'] = 'none';
        $this->setComponentConfig(['runtime' => ['network' => 'bridge']]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        $process = $container->run();
        self::assertEquals(0, $process->getExitCode());
        self::assertContains('64 bytes from', $process->getOutput());
    }

    public function testNetworkInvalidOverride()
    {
        $imageConfiguration = $this->getBuilderImageConfiguration();
        $imageConfiguration['data']['network'] = 'none';
        $this->setComponentConfig(['runtime' => ['network' => 'fooBar']]);
        try {
            $this->getContainer($imageConfiguration, [], [], true);
            self::fail('Invalid network must fail.');
        } catch (ApplicationException $e) {
            self::assertContains('not supported', $e->getMessage());
        }
    }
}
