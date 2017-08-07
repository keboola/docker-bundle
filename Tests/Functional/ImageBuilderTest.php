<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class ImageBuilderTest extends KernelTestCase
{
    const DOCKER_ERROR_MSG = 'Build failed (code: 1): Sending build context to Docker daemon 2.048 kB
Step 1 : FROM quay.io/keboola/docker-custom-r:latest
---> a72869638513
Step 2 : LABEL com.keboola.docker.runner.origin builder
---> Running in a87231b5bf43
---> 87f663f8c93c
Removing intermediate container a87231b5bf43
Step 3 : WORKDIR /home
---> Running in 683c54126fa8
---> 7dbffae5f1b6
Removing intermediate container 683c54126fa8
Step 4 : ENV APP_VERSION 1.7
---> Running in bc520b980770
---> 3661cfdbd53a
Removing intermediate container bc520b980770
Step 5 : RUN git clone -b 1.7 --depth 1 https://keboola/docker-demo-app /home/ || (echo "KBC::USER_ERR:Cannot access the Git repository https://github.com/keboola/docker-demo-app, please verify its URL, credentials and version.KBC::USER_ERR" && exit 1)
---> Running in 99e6f8091e9f
[91mCloning into \'/home\'...
[0m[91mNote: checking out \'af5c7a3995f61cc6515156be3f52d5eb6e3e91e7\'.

You are in \'detached HEAD\' state. You can look around, make experimental
changes and commit them, and you can discard any commits you make in this
state without impacting any branches by performing another checkout.

If you want to create a new branch to retain commits you create, you may
do so (now or later) by using -b with the checkout command again. Example:

git checkout -b new_branch_name

[0m / open /dev/mapper/docker-202:1-661201-2430efb83129dd524011bfbae4eb39476ca783faa2a42180fe578e642f002656: no such file or directory';

    const DOCKER_ERROR_MSG_2 = 'Build failed (code: 1): Sending build context to Docker daemon 3.584 kB

Step 1 : FROM quay.io/keboola/docker-custom-python:1.2.3
---> 40458b762166
Step 2 : LABEL com.keboola.docker.runner.origin builder
---> Using cache
---> 1d68d4503a7d
Step 3 : WORKDIR /home
---> Using cache
---> 56d743f551fd
Step 4 : ENV APP_VERSION v1.0.8
/ devicemapper: Error running deviceResume dm_task_run failed';

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

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
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

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testCreatePublicRepoWithTag()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $process = new Process("sudo docker images quay.io/keboola/docker-custom-php:1.1.0 | grep docker-custom-php | wc -l");
        $process->run();
        if (trim($process->getOutput() != 0)) {
            (new Process("sudo docker rmi quay.io/keboola/docker-custom-php:1.1.0"))->mustRun();
        }

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
                    "tag" => "1.1.0",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));

        $process = new Process("sudo docker images quay.io/keboola/docker-custom-php:1.1.0 | grep docker-custom-php | wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));
    }

    public function testCreatePrivateRepoPrivateHub()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare([]);
            $this->fail("Building from private image without login should fail");
        } catch (BuildException $e) {
            $this->assertContains('not found', $e->getMessage());
        }
    }


    public function testCreatePrivateRepoMissingPassword()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
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
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
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

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare($configData);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }

    public function testInvalidRepo()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare([]);
            $this->fail("Invalid repository must raise exception.");
        } catch (UserException $e) {
            $this->assertContains('Cannot access the repository', $e->getMessage());
        }
    }

    public function testCreateInvalidUrl()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare($configData);
            $this->fail("Invalid repository address must fail");
        } catch (UserException $e) {
            $this->assertContains('Invalid repository address', $e->getMessage());
        }
    }

    public function a()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $temp = new Temp();
        $builder = $this->getMockBuilder(ImageBuilder::class)
            ->setConstructorArgs([$encryptor, $imageConfig, new NullLogger()])
            ->setMethods(['getBuildCommand'])
            ->getMock();
        /** @var ImageBuilder $builder */
        $builder->setTemp($temp);
        $builder->method('getBuildCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sudo docker build --tag=' . escapeshellarg($builder->getFullImageId()) . " " . $temp->getTmpFolder()
            ));
        $builder->prepare([]);
    }

    public function testWeirdBugError2()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $temp = new Temp();
        $builder = $this->getMockBuilder(ImageBuilder::class)
            ->setConstructorArgs([$encryptor, $imageConfig, new NullLogger()])
            ->setMethods(['getBuildCommand'])
            ->getMock();
        /** @var ImageBuilder $builder */
        $builder->setTemp($temp);
        $builder->method('getBuildCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG_2 . '" && exit 1\'',
                'sudo docker build --tag=' . escapeshellarg($builder->getFullImageId()) . " " . $temp->getTmpFolder()
            ));
        $builder->prepare([]);
    }

    public function testWeirdBugTerminate()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-custom-php",
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

        $temp = new Temp();
        $builder = $this->getMockBuilder(ImageBuilder::class)
            ->setConstructorArgs([$encryptor, $imageConfig, new NullLogger()])
            ->setMethods(['getBuildCommand'])
            ->getMock();
        /** @var ImageBuilder $builder */
        $builder->setTemp($temp);
        $builder->method('getBuildCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sh -c -e \'echo "' . self::DOCKER_ERROR_MSG . '" && exit 1\'',
                'sudo docker build --tag=' . escapeshellarg($builder->getFullImageId()) . " " . $temp->getTmpFolder()
            ));
        try {
            $builder->prepare([]);
            $this->fail("Too many errors must fail");
        } catch (ApplicationException $e) {
        }
    }

    public function testQuayImage()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/docker-custom-php",
                    "build_options" => [
                        "parent_type" => "quayio",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app",
                            "type" => "git"
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                            " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install"
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ]
            ]
        ]);

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }

    public function testECRImage()
    {
        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ],
                    "build_options" => [
                        "parent_type" => "aws-ecr",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app",
                            "type" => "git"
                        ],
                        "commands" => [
                            "git clone --depth 1 {{repository}} /home/" .
                            " || (echo \"KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\" && exit 1)",
                            "cd /home/",
                            "composer install"
                        ],
                        "entry_point" => "php /home/run.php --data=/data"
                    ]
                ]
            ]
        ]);

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $this->assertContains("builder-", $image->getFullImageId());

        $process = new Process("sudo docker images | grep builder- | wc -l");
        $process->run();
        $this->assertEquals($oldCount + 1, trim($process->getOutput()));
    }
}
