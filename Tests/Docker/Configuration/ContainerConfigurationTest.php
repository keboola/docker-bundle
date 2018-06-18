<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;

class ContainerConfigurationTest extends TestCase
{
    public function testConfiguration()
    {
        (new Configuration\Container())->parse([
            "config" => [
                "storage" => [
                    "input" => [
                        "tables" => [
                            [
                                "source" => "in.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "tags" => ["tag1", "tag2"],
                                "query" => "esquery"
                            ]
                        ]
                    ],
                    "output" => [
                        "tables" => [
                            [
                                "source" => "test.csv",
                                "destination" => "out.c-main.data"
                            ]
                        ],
                        "files" => [
                            [
                                "source" => "file",
                                "tags" => ["tag"]
                            ]
                        ]
                    ]
                ],
                "parameters" => [
                    ["var1" => "val1"],
                    ["arr1" => ["var2" => "val2"]]
                ],
                "authorization" => [
                    "oauth_api" => [
                        "id" => 1234,
                        "credentials" => [
                            "token" => "123456",
                            "params" => [
                                "key" => "val"
                            ]
                        ]
                    ]
                ],
                "processors" => [
                    "before" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ],
                    "after" => [
                        [
                            "definition" => [
                                "component" => "a"
                            ],
                            "parameters" => [
                                "key" => "val"
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function testConfigurationEmpty()
    {
        $temp = new Temp();
        $temp->initRunFolder();
        $container = new Configuration\Container();
        $data = $container->parse(["config" => ['parameters' => [], 'image_parameters' => []]]);
        self::assertEquals(['parameters' => [], 'image_parameters' => []], $data);
        $adapter = new Adapter('json');
        $adapter->setConfig($data);
        $adapter->writeToFile($temp->getTmpFolder() . '/config.json');
        $string = file_get_contents($temp->getTmpFolder() . '/config.json');
        self::assertEquals("{\n    \"parameters\": {},\n    \"image_parameters\": {},\n    \"storage\": {},\n    \"authorization\": {}\n}", $string);
    }
}
