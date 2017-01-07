<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
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

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig, new Temp(), true);
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

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)"))->run();

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
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

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)"))->run();
    }
}
