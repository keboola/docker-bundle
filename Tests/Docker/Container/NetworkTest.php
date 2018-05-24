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
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains('64 bytes from', $process->getOutput());
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
            $this->fail('Ping must fail');
        } catch (UserException $e) {
            $this->assertContains('ping: unknown host', $e->getMessage());
        }
    }

    public function testNetworkBridgeOverride()
    {
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app", // not used, can by anything
                            "type" => "git",
                        ],
                        "entry_point" => "ping -W 10 -c 1 www.example.com",
                        "parameters" => [
                            [
                                "name" => "network",
                                "type" => "string",
                                "required" => false
                            ]
                        ]
                    ]
                ],
                "network" => "bridge"
            ]
        ];
        $this->setComponentConfig(['runtime' => ['network' => 'none']]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        try {
            $container->run();
            $this->fail('Ping must fail');
        } catch (UserException $e) {
            $this->assertContains('ping: unknown host', $e->getMessage());
        }
    }

    public function testNetworkBridgeOverrideNoValue()
    {
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app", // not used, can by anything
                            "type" => "git",
                        ],
                        "entry_point" => "ping -W 10 -c 1 www.example.com",
                        "parameters" => [
                            [
                                "name" => "network",
                                "type" => "string",
                                "required" => false
                            ]
                        ]
                    ]
                ],
                "network" => "bridge"
            ]
        ];
        $this->setComponentConfig(['runtime' => []]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkBridgeOverrideFail()
    {
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app", // not used, can by anything
                            "type" => "git",
                        ],
                        "entry_point" => "ping -W 10 -c 1 www.example.com",
                    ]
                ],
                "network" => "bridge"
            ]
        ];
        $this->setComponentConfig(['runtime' => ['network' => 'none']]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        // parameter is not defined in image, must be ignored
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkNoneOverride()
    {
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app", // not used, can by anything
                            "type" => "git",
                        ],
                        "entry_point" => "ping -W 10 -c 1 www.example.com",
                        "parameters" => [
                            [
                                "name" => "network",
                                "type" => "string",
                                "required" => false
                            ]
                        ]
                    ]
                ],
                "network" => "none"
            ]
        ];
        $this->setComponentConfig(['runtime' => ['network' => 'bridge']]);
        $container = $this->getContainer($imageConfiguration, [], [], true);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkInvalidOverride()
    {
        $imageConfiguration = [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app", // not used, can by anything
                            "type" => "git",
                        ],
                        "entry_point" => "ping -W 10 -c 1 www.example.com",
                        "parameters" => [
                            [
                                "name" => "network",
                                "type" => "string",
                                "required" => false
                            ]
                        ]
                    ]
                ],
                "network" => "none"
            ]
        ];
        $this->setComponentConfig(['runtime' => ['network' => 'fooBar']]);
        try {
            $this->getContainer($imageConfiguration, [], [], true);
            $this->fail("Invalid network must fail.");
        } catch (ApplicationException $e) {
            $this->assertContains('not supported', $e->getMessage());
        }
    }
}
