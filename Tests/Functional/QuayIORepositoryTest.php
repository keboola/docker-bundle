<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
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
        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app"))->run();
        # fixing a weird bug
        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app:1.0.14"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        $imageConfig = array(
            "definition" => array(
                "type" => "quayio",
                "uri" => "keboola/docker-demo-app",
                "tag" => "1.0.14"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log, $containerLog);
        $image->prepare($container, [], uniqid());

        $this->assertEquals("quay.io/keboola/docker-demo-app:1.0.14", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app"))->run();
        # fixing a weird bug
        (new Process("sudo docker rmi quay.io/keboola/docker-demo-app:1.0.14"))->run();

    }
}
