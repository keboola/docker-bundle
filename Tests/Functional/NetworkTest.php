<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class NetworkTest extends KernelTestCase
{
    /**
     * @var Temp
     */
    private $temp;

    private function getContainer($imageConfig)
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new Container($image, $log, $containerLog);
        $container->setDataDir($this->temp->getTmpFolder());
        return $container;
    }

    public function setUp()
    {
        $this->temp = new Temp('docker');
        $this->temp->setId(123456);
        $this->temp->initRunFolder();
        self::bootKernel();
    }


    public function tearDown()
    {
        parent::tearDown();
        // clean temporary folder
        $fs = new Filesystem();
        $fs->remove($this->temp->getTmpFolder());

        (new Process("sudo docker rmi -f $(sudo docker images -aq --filter \"label=com.keboola.docker.runner.origin=builder\")"))->run();
    }


    public function testNetworkBridge()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "entry_point" => "ping -W 10 -c 1 www.example.com"
                ]
            ],
            "configuration_format" => "yaml",
            "network" => "bridge",
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        $process = $container->run("testsuite", []);
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }


    public function testNetworkNone()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "entry_point" => "ping -W 10 -c 1 www.example.com"
                ]
            ],
            "configuration_format" => "yaml",
            "network" => "none"
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        try {
            $container->run("testsuite", []);
            $this->fail("Ping must fail");
        } catch (ApplicationException $e) {
            $this->assertContains("unknown host www.example.com", $e->getMessage());
        }
    }

    public function testNetworkBridgeOverride()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
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
            "configuration_format" => "yaml",
            "network" => "bridge"
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        try {
            $container->run("testsuite", ['runtime' => ['network' => 'none']]);
            $this->fail("Ping must fail");
        } catch (ApplicationException $e) {
            $this->assertContains("unknown host www.example.com", $e->getMessage());
        }
    }

    public function testNetworkBridgeOverrideNoValue()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
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
            "configuration_format" => "yaml",
            "network" => "bridge"
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        // parameter is not defined in image, must be ignored
        $process = $container->run("testsuite", ['runtime' => []]);
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkBridgeOverrideFail()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "entry_point" => "ping -W 10 -c 1 www.example.com",
                ]
            ],
            "configuration_format" => "yaml",
            "network" => "bridge"
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        // parameter is not defined in image, must be ignored
        $process = $container->run("testsuite", ['runtime' => ['network' => 'none']]);
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkNoneOverride()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
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
            "configuration_format" => "yaml",
            "network" => "none",
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        $process = $container->run("testsuite", ['runtime' => ['network' => 'bridge']]);
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkInvalidOverride()
    {
        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
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
            "configuration_format" => "yaml",
            "network" => "none",
        ];

        $container = $this->getContainer($imageConfig);
        $container->setId("network-bridge-test");
        try {
            $container->run("testsuite", ['runtime' => ['network' => 'fooBar']]);
            $this->fail("Invalid network must fail.");
        } catch (ApplicationException $e) {
            $this->assertContains('not supported', $e->getMessage());
        }
    }
}
