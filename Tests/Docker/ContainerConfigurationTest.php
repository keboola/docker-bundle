<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Image;


class ContainerConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        (new Configuration\Container())->parse(array(
            "config" => array(
                "system" => array(
                    "image_tag" => "0.6"
                ),
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
                "user" => array(
                    array("var1" => "val1"),
                    array("arr1" => array("var2" => "val2"))
                )
            )
        ));
    }

}
