<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
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
        $imageConfig = array(
            "definition" => array(
                "type" => "quayio-private",
                "uri" => "keboola/docker-demo-private"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $image->prepare($container, [], uniqid());
    }

    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi quay.io/keboola/docker-demo-private"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = array(
            "definition" => array(
                "type" => "quayio-private",
                "uri" => "keboola/docker-demo-private",
                "repository" => array(
                    "username" => QUAYIO_PRIVATE_USERNAME,
                    "#password" => $encryptor->encrypt(QUAYIO_PRIVATE_PASSWORD)
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

        $this->assertEquals("quay.io/keboola/docker-demo-private:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-private"))->run();
    }
}
