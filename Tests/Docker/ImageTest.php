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
        $this->assertEquals('yaml', $image->getConfigFormat());
        $this->assertEquals(false, $image->getForwardToken());
        $this->assertEquals(false, $image->getForwardTokenDetails());

        $configuration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200,
            "forward_token" => true,
            "forward_token_details" => true,
            "streaming_logs" => true,
            "configuration_format" => 'json'
        );
        $image = Image::factory($configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\DockerHub", get_class($image));
        $this->assertEquals("128m", $image->getMemory());
        $this->assertEquals(2048, $image->getCpuShares());
        $this->assertEquals(7200, $image->getProcessTimeout());
        $this->assertEquals(true, $image->getForwardToken());
        $this->assertEquals(true, $image->getForwardTokenDetails());
        $this->assertEquals(true, $image->isStreamingLogs());
        $this->assertEquals('json', $image->getConfigFormat());
    }

    public function testDockerHubPrivateRepository()
    {
        $configuration = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboola/docker-demo",
                "repository" => array(
                    "email" => "aa",
                    "password" => "bb",
                    "username" => "cc",
                    "server" => "dd"
                )
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200
        );
        $image = Image::factory($configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\DockerHub\\PrivateRepository", get_class($image));
        $this->assertEquals("aa", $image->getLoginEmail());
        $this->assertEquals("bb", $image->getLoginPassword());
        $this->assertEquals("cc", $image->getLoginUsername());
        $this->assertEquals("dd", $image->getLoginServer());

        $this->assertEquals("--email='aa' --username='cc' --password='bb' 'dd'", $image->getLoginParams());
        $this->assertEquals("'dd'", $image->getLogoutParams());
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
