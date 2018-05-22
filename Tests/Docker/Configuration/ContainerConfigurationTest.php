<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;

class ContainerConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        (new Configuration\Container())->parse(array(
            "config" => array(
                "storage" => array(
                    "input" => array(
                        "tables" => array(
                            array(
                                "source" => "in.c-main.data"
                            )
                        ),
                        "files" => array(
                            array(
                                "tags" => array("tag1", "tag2"),
                                "query" => "esquery"
                            )
                        )
                    ),
                    "output" => array(
                        "tables" => array(
                            array(
                                "source" => "test.csv",
                                "destination" => "out.c-main.data"
                            )
                        ),
                        "files" => array(
                            array(
                                "source" => "file",
                                "tags" => array("tag")
                            )
                        )
                    )
                ),
                "parameters" => array(
                    array("var1" => "val1"),
                    array("arr1" => array("var2" => "val2"))
                ),
                "authorization" => array(
                    "oauth_api" => array(
                        "id" => 1234,
                        "credentials" => array(
                            "token" => "123456",
                            "params" => array(
                                "key" => "val"
                            )
                        )
                    )
                ),
                "processors" => array(
                    "before" => array(
                        array(
                            "definition" => array(
                                "component" => "a"
                            ),
                            "parameters" => array(
                                "key" => "val"
                            )
                        )
                    ),
                    "after" => array(
                        array(
                            "definition" => array(
                                "component" => "a"
                            ),
                            "parameters" => array(
                                "key" => "val"
                            )
                        )
                    )
                )
            )
        ));
    }
}
