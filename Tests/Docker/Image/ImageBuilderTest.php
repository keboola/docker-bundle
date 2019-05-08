<?php

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class ImageBuilderTest extends BaseImageTest
{
    public function tearDown()
    {
        parent::tearDown();
        (new Process(
            'sudo docker rmi -f $(sudo docker images -aq --filter \'label=com.keboola.docker.runner.origin=builder\')'
        ))->run();
    }

    public function testCreatePrivateRepo()
    {
        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://bitbucket.org/keboolaprivatetest/docker-demo-app.git',
                            'type' => 'git',
                            '#password' => $this->getEncryptor()->encrypt(GIT_PRIVATE_PASSWORD),
                            'username' => GIT_PRIVATE_USERNAME,
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                                ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertContains('builder-', $image->getFullImageId());

        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        self::assertEquals($oldCount + 1, trim($process->getOutput()));
    }


    public function testCreatePublicRepo()
    {
        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                                ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertContains('builder-', $image->getFullImageId());

        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        self::assertEquals($oldCount + 1, trim($process->getOutput()));
    }

    public function testCreatePrivateRepoMissingPassword()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://bitbucket.org/keboolaprivatetest/docker-demo-app.git',
                            'type' => 'git',
                            'username' => GIT_PRIVATE_USERNAME,
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/ || (echo ' .
                                '\'KBC::USER_ERR:Cannot access the repository {{repository}}.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare([]);
            $this->fail('Building from private repository without login should fail');
        } catch (BuildParameterException $e) {
            self::assertContains(
                'Cannot access the repository https://bitbucket.org/keboolaprivatetest',
                $e->getMessage()
            );
        }
    }

    public function testCreatePrivateRepoMissingCredentials()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://bitbucket.org/keboolaprivatetest/docker-demo-app.git',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/ || echo ' .
                                '\'KBC::USER_ERR:Cannot access the repository {{repository}}.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare([]);
            $this->fail('Building from private repository without login should fail');
        } catch (BuildParameterException $e) {
            self::assertContains(
                'Cannot access the repository https://bitbucket.org/keboolaprivatetest',
                $e->getMessage()
            );
        }
    }

    public function testCreatePrivateRepoViaParameters()
    {
        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => '',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                                ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /{{dir}}/',
                            'composer install'
                        ],
                        'parameters' => [
                            [
                                'name' => 'repository',
                                'type' => 'string',
                            ],
                            [
                                'name' => 'username',
                                'type' => 'string',
                            ],
                            [
                                'name' => '#password',
                                'type' => 'string',
                            ],
                            [
                                'name' => 'dir',
                                'type' => 'string',
                            ],
                        ],
                        'entry_point' => 'php /home/run.php --data=/data'
                    ],
                ],
            ],
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
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare($configData);
        self::assertContains('builder-', $image->getFullImageId());

        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        self::assertEquals($oldCount + 1, trim($process->getOutput()));
    }

    public function testInvalidRepo()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/non-existent-repo',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                                ' || echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1 ',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare([]);
            $this->fail('Invalid repository must raise exception.');
        } catch (UserException $e) {
            self::assertContains('Cannot access the repository', $e->getMessage());
        }
    }

    public function testCreateInvalidUrl()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => '',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                            ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'parameters' => [
                            [
                                'name' => 'repository',
                                'type' => 'string',
                            ],
                            [
                                'name' => 'username',
                                'type' => 'string',
                            ],
                            [
                                'name' => '#password',
                                'type' => 'string',
                            ],
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $configData = [
            'runtime' => [
                'repository' => 'git@github.com:keboola/docker-bundle.git',
                'username' => GIT_PRIVATE_USERNAME,
                '#password' => GIT_PRIVATE_PASSWORD,
            ]
        ];
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        try {
            $image->prepare($configData);
            $this->fail('Invalid repository address must fail');
        } catch (UserException $e) {
            self::assertContains('Invalid repository address', $e->getMessage());
        }
    }

    public function testQuayImage()
    {
        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                            ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertContains('builder-', $image->getFullImageId());

        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        self::assertEquals($oldCount + 1, trim($process->getOutput()));
    }

    public function testECRImage()
    {
        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        $oldCount = intval(trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/docker-demo',
                    'build_options' => [
                        'parent_type' => 'aws-ecr',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app',
                            'type' => 'git',
                        ],
                        'commands' => [
                            'git clone --depth 1 {{repository}} /home/' .
                            ' || (echo \'KBC::USER_ERR:Cannot access the repository.KBC::USER_ERR\' && exit 1)',
                            'cd /home/',
                            'composer install',
                        ],
                        'entry_point' => 'php /home/run.php --data=/data',
                    ],
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertContains('builder-', $image->getFullImageId());

        $process = new Process('sudo docker images | grep builder- | wc -l');
        $process->run();
        self::assertEquals($oldCount + 1, trim($process->getOutput()));
    }
}
