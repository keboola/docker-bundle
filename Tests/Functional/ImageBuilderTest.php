<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ImageBuilderTest extends KernelTestCase
{

    public function setUp()
    {
        self::bootKernel();
    }

    public function testCreatePrivateRepo()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                        "type" => "git",
                        "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                        "username" => GIT_PRIVATE_USERNAME,
                    ],
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

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container, [], uniqid());
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
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
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

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container, [], uniqid());
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
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => [
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "password" => DOCKERHUB_PRIVATE_PASSWORD,
                    "username" => DOCKERHUB_PRIVATE_USERNAME,
                    "server" => DOCKERHUB_PRIVATE_SERVER,
                ],
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                        "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                        "username" => GIT_PRIVATE_USERNAME,
                    ],
                    "commands" => [
                        // use other directory than home, that is already used by docker-demo-docker
                        "git clone {{repository}} /home/src2/",
                        "cd /home/src2/",
                        "composer install",
                    ],
                    "entry_point" => "php /home/src2/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        $tag = $image->prepare($container, [], uniqid());
        $this->assertContains("builder-", $tag);

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testCreatePrivateRepoPrivateHubMissingCredentials()
    {
        // remove image from cache
        $process = new Process("sudo docker rmi -f keboolaprivatetest/docker-demo-docker");
        $process->run();

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "server" => DOCKERHUB_PRIVATE_SERVER,
                ),
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                        "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                        "username" => GIT_PRIVATE_USERNAME,
                    ],
                    "commands" => [
                        // use other directory than home, that is already used by docker-demo-docker
                        "git clone {{repository}} /home/src2/",
                        "cd /home/src2/",
                        "composer install",
                    ],
                    "entry_point" => "php /home/src2/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        try {
            $image->prepare($container, [], uniqid());
            $this->fail("Building from private image without login should fail");
        } catch (BuildException $e) {
            $this->assertContains('not found', $e->getMessage());
        }
    }


    public function testCreatePrivateRepoMissingPassword()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                        "type" => "git",
                        "username" => GIT_PRIVATE_USERNAME,
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd /home/",
                        "composer install",
                    ],
                    "entry_point" => "php /home/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        try {
            $image->prepare($container, [], uniqid());
            $this->fail("Building from private repository without login should fail");
        } catch (BuildException $e) {
            $this->assertContains('Authentication failed', $e->getMessage());
        }
    }


    public function testCreatePrivateRepoMissingCredentials()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/base-php",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                        "type" => "git",
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd /home/",
                        "composer install",
                    ],
                    "entry_point" => "php /home/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];

        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new Container($image, $log);
        try {
            $image->prepare($container, [], uniqid());
            $this->fail("Building from private repository without login should fail");
        } catch (BuildException $e) {
            $this->assertContains('could not read Username', $e->getMessage());
        }
    }
}
