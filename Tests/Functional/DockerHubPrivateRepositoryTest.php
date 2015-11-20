<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Service\ObjectEncryptor;
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
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor(self::$kernel->getContainer());
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $image->prepare($container, [], uniqid());
    }

    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImage()
    {
        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "password" => DOCKERHUB_PRIVATE_PASSWORD,
                    "username" => DOCKERHUB_PRIVATE_USERNAME,
                    "server" => DOCKERHUB_PRIVATE_SERVER
                )
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor(self::$kernel->getContainer());
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $image->prepare($container, [], uniqid());

        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();
    }


    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                    "username" => DOCKERHUB_PRIVATE_USERNAME,
                    "server" => DOCKERHUB_PRIVATE_SERVER
                )
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $image->prepare($container, [], uniqid());

        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();
    }
}
