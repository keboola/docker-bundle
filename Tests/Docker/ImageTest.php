<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;

class ImageTest extends \PHPUnit_Framework_TestCase
{
    public function testFactory()
    {
        $image = Image::factory();
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image", get_class($image));
        $this->assertEquals("64m", $image->getMemory());
        $this->assertEquals(1024, $image->getCpuShares());


        $configuration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200
        );
        $image = Image::factory($configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\DockerHub", get_class($image));
        $this->assertEquals("128m", $image->getMemory());
        $this->assertEquals(2048, $image->getCpuShares());
        $this->assertEquals(7200, $image->getProcessTimeout());
    }


    public function testFormat()
    {
        $image = Image::factory();
        $image->setConfigFormat('yaml');
        $this->assertEquals('yaml', $image->getConfigFormat());
        try {
            $image->setConfigFormat('fooBar');
            $this->fail("Invalid format should cause exception.");
        } catch (\Exception $e) {
        }
    }
}
