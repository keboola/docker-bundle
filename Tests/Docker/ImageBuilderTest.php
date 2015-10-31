<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ImageBuilderTest extends KernelTestCase
{
    public function setUp()
    {
        self::bootKernel();
    }

    public function testDockerFile()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
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
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, []);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileNotExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $expectedFile = <<<DOCKERFILE
FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd /home/
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data
DOCKERFILE;
        $this->assertEquals($expectedFile, trim($dockerFile));
    }


    public function testDockerFileVersion()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
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
                    "entry_point" => "php /home/run.php --data=/data",
                    "version" => '1.0.2',
                ],
            ],
            "configuration_format" => "yaml",
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, []);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileNotExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $expectedFile =
            'FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Version 1.0.2
# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd /home/
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data';
        $this->assertEquals($expectedFile, trim($dockerFile));
    }


    public function testDockerFileParameters()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd {{foo}} {{bar}}",
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data",
                    "parameters" => [
                        [
                            "name" => "foo",
                            "type" => "string"
                        ],
                        [
                            "name" => "bar",
                            "type" => "plain_string"
                        ]
                    ]
                ]
            ],
            "configuration_format" => "yaml",
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, ['parameters' => ['foo' => 'fooBar', 'bar' => 'baz']]);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileNotExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $expectedFile =
            'FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd fooBar baz
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data';
        $this->assertEquals($expectedFile, trim($dockerFile));
    }


    public function testDockerFileUndefParameters()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd {{foo}} {{bar}}",
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data",
                    "parameters" => [
                        [
                            "name" => "foo",
                            "type" => "string"
                        ]
                    ]
                ]
            ],
            "configuration_format" => "yaml",
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        try {
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
            $reflection->setAccessible(true);
            $reflection->invoke($image, ['parameters' => ['foo' => 'fooBar', 'bar' => 'baz']]);
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
            $reflection->setAccessible(true);
            $reflection->invoke($image, $tempDir->getTmpFolder());
            $this->fail("Missing parameter definition must raise exception.");
        } catch (BuildParameterException $e) {
            $this->assertContains('Orphaned parameter', $e->getMessage());
        }
    }


    public function testDockerFileParametersMissingValue()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "git",
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/",
                        "cd {{foo}} {{bar}}",
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data",
                    "parameters" => [
                        [
                            "name" => "foo",
                            "type" => "string"
                        ],
                        [
                            "name" => "bar",
                            "type" => "plain_string"
                        ]
                    ]
                ]
            ],
            "configuration_format" => "yaml",
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        try {
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
            $reflection->setAccessible(true);
            $reflection->invoke($image, ['parameters' => ['foo' => 'fooBar']]);
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
            $reflection->setAccessible(true);
            $reflection->invoke($image, $tempDir->getTmpFolder());
            $this->fail("Missing value of parameter must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('has no value', $e->getMessage());
        }
    }


    public function testGitCredentials()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
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
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, []);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $expectedFile = <<<DOCKERFILE
FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Repository initialization
COPY .git-credentials /tmp/.git-credentials
RUN git config --global credential.helper 'store --file=/tmp/.git-credentials'

# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd /home/
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data
DOCKERFILE;
        $this->assertEquals($expectedFile, trim($dockerFile));
        $credentials = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $this->assertEquals(
            'https://keboolaprivatetest:uH11KyDFnG1G8gPHHzmn@github.com/keboola/docker-demo-app',
            trim($credentials)
        );
    }

    public function testInvalidRepository()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app",
                        "type" => "fooBar",
                    ],
                    "commands" => [
                        "composer install"
                    ],
                    "entry_point" => "php /home/run.php --data=/data"
                ]
            ],
            "configuration_format" => "yaml",
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        try {
            Image::factory($encryptor, $log, $imageConfig);
            $this->fail("Invalid repository should fail.");
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('Invalid repository_type', $e->getMessage());
        }
    }
}
