<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class QuayIOPrivateRepositoryTest extends BaseImageTest
{
    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testMissingCredentials()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private"
                ],
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testInvalidCredentials()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "username" => QUAYIO_PRIVATE_USERNAME . "_invalid",
                        "#password" => $this->getEncryptor()->encrypt(QUAYIO_PRIVATE_PASSWORD),
                        "server" => DOCKERHUB_PRIVATE_SERVER
                    ]

                ],
                "memory" => "64m",
                "configuration_format" => "json"
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-demo-private)"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "username" => QUAYIO_PRIVATE_USERNAME,
                        "#password" => $this->getEncryptor()->encrypt(QUAYIO_PRIVATE_PASSWORD)
                    ]
                ],
                "memory" => "64m",
                "configuration_format" => "json"
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals("quay.io/keboola/docker-demo-private:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-private"))->run();
    }
}
