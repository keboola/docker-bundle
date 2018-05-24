<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class QuayIORepositoryTest extends BaseImageTest
{
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
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals("quay.io/keboola/docker-demo-app:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app"))->run();
    }
}
