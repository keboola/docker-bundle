<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class QuayIOPrivateRepositoryTest extends KernelTestCase
{
    public function setUp()
    {
        self::bootKernel();
    }

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
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testInvalidCredentials()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "username" => QUAYIO_PRIVATE_USERNAME . "_invalid",
                        "#password" => $encryptor->encrypt(QUAYIO_PRIVATE_PASSWORD),
                        "server" => DOCKERHUB_PRIVATE_SERVER
                    ]

                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ],
        ]);
        $image = Image::factory($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }


    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-demo-app)"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "username" => QUAYIO_PRIVATE_USERNAME,
                        "#password" => $encryptor->encrypt(QUAYIO_PRIVATE_PASSWORD)
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ],
        ]);
        $image = Image::factory($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals("quay.io/keboola/docker-demo-private:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-private"))->run();
    }
}
