<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DockerHubPrivateRepositoryTest extends \PHPUnit_Framework_TestCase
{
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
        $image = Image::factory($imageConfig);
        $container = new Container($image, $log);
        $image->prepare($container);
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
        $image = Image::factory($imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container);

        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $tag);

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();
    }
}
