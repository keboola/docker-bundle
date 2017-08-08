<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\Syrup\Exception\ApplicationException;

class ComponentTest extends \PHPUnit_Framework_TestCase
{
    public function testConfiguration()
    {
        $configuration = [
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200,
                "forward_token" => true,
                "forward_token_details" => true,
                "default_bucket" => true,
                "configuration_format" => 'json'
            ]
        ];

        $component = new Component($configuration);
        $this->assertEquals("128m", $component->getMemory());
        $this->assertEquals(2048, $component->getCpuShares());
        $this->assertEquals(7200, $component->getProcessTimeout());
        $this->assertEquals('standard', $component->getLoggerType());
        $this->assertEquals('tcp', $component->getLoggerServerType());
        $this->assertEquals(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
            ],
            $component->getLoggerVerbosity()
        );
        $this->assertEquals(true, $component->forwardToken());
        $this->assertEquals(true, $component->forwardTokenDetails());
        $this->assertEquals(true, $component->hasDefaultBucket());
    }

    public function testConfigurationDefaults()
    {
        $configuration = [
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
            ]
        ];

        $component = new Component($configuration);
        $this->assertEquals("64m", $component->getMemory());
        $this->assertEquals(1024, $component->getCpuShares());
        $this->assertEquals(3600, $component->getProcessTimeout());
        $this->assertEquals('standard', $component->getLoggerType());
        $this->assertEquals('tcp', $component->getLoggerServerType());
        $this->assertEquals(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
            ],
            $component->getLoggerVerbosity()
        );
        $this->assertEquals(false, $component->forwardToken());
        $this->assertEquals(false, $component->forwardTokenDetails());
        $this->assertEquals(false, $component->hasDefaultBucket());
    }

    public function testInvalidDefinition()
    {
        try {
            new Component([]);
            $this->fail("Invalid image definition must fail.");
        } catch (ApplicationException $e) {
            $this->assertContains('definition is empty', $e->getMessage());
        }
    }

    public function testGetSanitizedBucketNameDot()
    {
        $component = [
            'id' => 'keboola.ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        $this->assertEquals('in.c-keboola-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testGetSanitizedBucketNameNoDot()
    {
        $component = [
            'id' => 'ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        $this->assertEquals('in.c-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testGetSanitizedBucketNameTwoDot()
    {
        $component = [
            'id' => 'keboola.ex.generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        $this->assertEquals('in.c-keboola-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testInvalidRepository()
    {
        try {
            new Component([
                "data" => [
                    "definition" => [
                        "type" => "builder",
                        "uri" => "keboolaprivatetest/docker-demo-docker",
                        "build_options" => [
                            "parent_type" => "dockerhub-private",
                            "repository" => [
                                "uri" => "https://github.com/keboola/docker-demo-app",
                                "type" => "fooBar",
                            ],
                            "commands" => [
                                "composer install"
                            ],
                            "entry_point" => "php /home/run.php --data=/data"
                        ]
                    ],
                    "configuration_format" => "yaml",
                ]
            ]);
        } catch (ApplicationException $e) {
            $this->assertContains('Invalid repository_type', $e->getMessage());
        }
    }
}
