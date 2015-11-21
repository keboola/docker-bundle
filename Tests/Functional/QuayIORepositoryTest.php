<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
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
     * Try do download private image using credentials
     */
    public function testDownloadedImage()
    {
        (new Process("sudo docker rmi quay.io/keboola/demo"))->run();
        # fixing a weird bug
        (new Process("sudo docker rmi quay.io/keboola/demo:latest"))->run();
        (new Process("sudo docker rmi quay.io/keboola/demo:master"))->run();

        $process = new Process("sudo docker images | grep quay.io/keboola/demo | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        $imageConfig = array(
            "definition" => array(
                "type" => "quayio",
                "uri" => "keboola/demo"
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

        $this->assertEquals("quay.io/keboola/demo:latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep quay.io/keboola/demo | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi quay.io/keboola/demo"))->run();
        # fixing a weird bug
        (new Process("sudo docker rmi quay.io/keboola/demo:latest"))->run();
        (new Process("sudo docker rmi quay.io/keboola/demo:master"))->run();

    }
}
