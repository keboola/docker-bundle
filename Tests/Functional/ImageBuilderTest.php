<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class ImageBuilderTest extends KernelTestCase
{
    public function setUp()
    {
        self::bootKernel();
    }

    public function tearDown()
    {
        parent::tearDown();
        (new Process(
            "sudo docker rmi -f $(sudo docker images -aq --filter \"label=com.keboola.docker.runner.origin=builder\")"
        ))->run();
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

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                            "type" => "git",
                            "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                            "username" => GIT_PRIVATE_USERNAME,
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                                " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install"
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

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

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app",
                            "type" => "git",
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                                " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install"
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

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

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "repository" => [
                        "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
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
                            "git clone --depth 1 {{repository}} /home/src2/" .
                                " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/src2/ && composer install",
                        ],
                        "entry_point" => "php /home/src2/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

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

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "repository" => [
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
                            "git clone --depth 1 {{repository}} /home/src2/" .
                                " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/src2/",
                            "composer install",
                        ],
                        "entry_point" => "php /home/src2/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        try {
            $image->prepare([]);
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

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                            "type" => "git",
                            "username" => GIT_PRIVATE_USERNAME,
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/ || (echo " .
                                "\"KBC::USER_ERR:Cannot access the repository {{repository}}.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install",
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        try {
            $image->prepare([]);
            $this->fail("Building from private repository without login should fail");
        } catch (BuildParameterException $e) {
            $this->assertContains(
                'Cannot access the repository https://bitbucket.org/keboolaprivatetest',
                $e->getMessage()
            );
        }
    }


    public function testCreatePrivateRepoMissingCredentials()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://bitbucket.org/keboolaprivatetest/docker-demo-app.git",
                            "type" => "git",
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/ || echo " .
                                "\"KBC::USER_ERR:Cannot access the repository {{repository}}.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install",
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        try {
            $image->prepare([]);
            $this->fail("Building from private repository without login should fail");
        } catch (BuildParameterException $e) {
            $this->assertContains(
                'Cannot access the repository https://bitbucket.org/keboolaprivatetest',
                $e->getMessage()
            );
        }
    }


    public function testCreatePrivateRepoViaParameters()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "",
                            "type" => "git",
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                                " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /{{dir}}/",
                            "composer install"
                        ],
                        "parameters" => [
                            [
                                "name" => "repository",
                                "type" => "string"
                            ],
                            [
                                "name" => "username",
                                "type" => "string"
                            ],
                            [
                                "name" => "#password",
                                "type" => "string"
                            ],
                            [
                                "name" => "dir",
                                "type" => "string"
                            ],
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $configData = [
            'parameters' => [
                'dir' => 'home',
            ],
            'runtime' => [
                'repository' => 'https://bitbucket.org/keboolaprivatetest/docker-demo-app.git',
                'username' => GIT_PRIVATE_USERNAME,
                '#password' => GIT_PRIVATE_PASSWORD,
            ]
        ];
        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare($configData);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testInvalidRepo()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://github.com/keboola/non-existent-repo",
                            "type" => "git",
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                                " || echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1 ",
                            "cd /home/",
                            "composer install"
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "yaml",
            ]
        ]);

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        try {
            $image->prepare([]);
            $this->fail("Invalid repository must raise exception.");
        } catch (UserException $e) {
            $this->assertContains('Cannot access the repository', $e->getMessage());
        }
    }

    public function testCreateInvalidUrl()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/base-php70",
                    "build_options" => [
                        "repository" => [
                            "uri" => "",
                            "type" => "git",
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                            " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install"
                        ],
                        "parameters" => [
                            [
                                "name" => "repository",
                                "type" => "string"
                            ],
                            [
                                "name" => "username",
                                "type" => "string"
                            ],
                            [
                                "name" => "#password",
                                "type" => "string"
                            ],
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ],
                "configuration_format" => "json",
            ]
        ]);

        $configData = [
            'runtime' => [
                'repository' => 'git@github.com:keboola/docker-bundle.git',
                'username' => GIT_PRIVATE_USERNAME,
                '#password' => GIT_PRIVATE_PASSWORD,
            ]
        ];
        $image = Image::factory($encryptor, $log, $imageConfig, true);
        try {
            $image->prepare($configData);
            $this->fail("Invalid repository address must fail");
        } catch (UserException $e) {
            $this->assertContains('Invalid repository address', $e->getMessage());
        }
    }
}
