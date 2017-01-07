<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
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

    private function getContainer(Component $imageConfig, array $componentConfig)
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $imageConfig, new Temp(), true);
        $image->prepare($componentConfig);

        $container = new Container(
            'docker-network-test',
            $image,
            $log,
            $containerLog,
            $this->temp->getTmpFolder(),
            [],
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
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
        $fs = new Filesystem();
        $fs->remove($this->temp->getTmpFolder());

        (new Process(
            "sudo docker rmi -f $(sudo docker images -a -q --filter \"label=com.keboola.docker.runner.origin=builder\")"
        ))->run();
    }

    public function testNetworkBridge()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "bridge",
            ]
        ]);

        $container = $this->getContainer($imageConfig, []);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkNone()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "none"
            ]
        ]);

        $container = $this->getContainer($imageConfig, []);
        try {
            $container->run();
            $this->fail("Ping must fail");
        } catch (ApplicationException $e) {
            $this->assertContains("unknown host www.example.com", $e->getMessage());
        }
    }

    public function testNetworkBridgeOverride()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "bridge"
            ]
        ]);

        $container = $this->getContainer($imageConfig, ['runtime' => ['network' => 'none']]);
        try {
            $container->run();
            $this->fail("Ping must fail");
        } catch (ApplicationException $e) {
            $this->assertContains("unknown host www.example.com", $e->getMessage());
        }
    }

    public function testNetworkBridgeOverrideNoValue()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "bridge"
            ]
        ]);

        $container = $this->getContainer($imageConfig, ['runtime' => []]);
        // parameter is not defined in image, must be ignored
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkBridgeOverrideFail()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "bridge"
            ]
        ]);

        $container = $this->getContainer($imageConfig, ['runtime' => ['network' => 'none']]);
        // parameter is not defined in image, must be ignored
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkNoneOverride()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "none",
            ]
        ]);

        $container = $this->getContainer($imageConfig, ['runtime' => ['network' => 'bridge']]);
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("64 bytes from", $process->getOutput());
    }

    public function testNetworkInvalidOverride()
    {
        $imageConfig = new Component([
            "data" => [
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
                "network" => "none",
            ]
        ]);

        try {
            $this->getContainer($imageConfig, ['runtime' => ['network' => 'fooBar']]);
            $this->fail("Invalid network must fail.");
        } catch (ApplicationException $e) {
            $this->assertContains('not supported', $e->getMessage());
        }
    }
}
