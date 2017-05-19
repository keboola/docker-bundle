<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class QuayIORepositoryTest extends KernelTestCase
{
    public function setUp()
    {
        self::bootKernel();
    }

    /**
     * Try do download image from Quay.io repository
     */
    public function testDownloadedImage()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-demo-app)"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio",
                    "uri" => "keboola/docker-demo-app"
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals("quay.io/keboola/docker-demo-app:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app"))->run();
    }
}
