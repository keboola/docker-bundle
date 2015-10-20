<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Process\Process;

class ImageBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatePrivateRepo()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository_type" => "git",
                    "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                    "'username" => GIT_PRIVATE_USERNAME,
                    "repository" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd /home/",
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container);
        $this->assertContains("builder-", $tag);

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testCreatePublicRepo()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository_type" => "git",
                    "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                    "'username" => GIT_PRIVATE_USERNAME,
                    "repository" => "https://github.com/keboola/docker-demo-app",
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd /home/",
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container);
        $this->assertContains("builder-", $tag);

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testCreatePrivateRepoPrivateHub()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "password" => DOCKERHUB_PRIVATE_PASSWORD,
                    "username" => DOCKERHUB_PRIVATE_USERNAME,
                    "server" => DOCKERHUB_PRIVATE_SERVER
                ),
                "build_options" => [
                    "repository_type" => "git",
                    "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                    "username" => GIT_PRIVATE_USERNAME,
                    "repository" => "https://github.com/keboola/docker-demo-app",
                    "commands" => [
                        // use other directory than home, that is already used by docker-demo-docker
                        "git clone {{repository}} /home/src2/",
                        "cd /home/src2/",
                        "composer install"
                    ],
                    "entry_point" => "php /home/src2/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container);
        $this->assertContains("builder-", $tag);

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }







    public function testMissingCredentials()
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
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));
        $image = Image::factory($encryptor, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container);

        $this->assertEquals("keboolaprivatetest/docker-demo-docker:latest", $tag);

        $process = new Process("sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi keboolaprivatetest/docker-demo-docker"))->run();
    }
}
