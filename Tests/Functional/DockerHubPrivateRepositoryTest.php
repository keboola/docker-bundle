<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class DockerHubPrivateRepositoryTest extends KernelTestCase
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
                    "type" => "dockerhub-private",
                    "uri" => "keboolaprivatetest/docker-demo-docker"
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testInvalidCredentials()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub-private",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "repository" => [
                        "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                        "username" => DOCKERHUB_PRIVATE_USERNAME . "_invalid",
                        "server" => DOCKERHUB_PRIVATE_SERVER
                    ]

                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi -f $(sudo docker images --filter=\"label=com.keboola.docker.runner.origin=builder\" -aq)"))->run();
        (new Process("sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)"))->run();
        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub-private",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "repository" => [
                        "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                        "username" => DOCKERHUB_PRIVATE_USERNAME,
                        "server" => DOCKERHUB_PRIVATE_SERVER
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)"))->run();
    }
}
