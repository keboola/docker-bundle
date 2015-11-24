<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ImageBuilderTest extends \PHPUnit_Framework_TestCase
{

    public function testDockerFile()
    {
        $encryptor = new ObjectEncryptor();

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
        $encryptor = new ObjectEncryptor();

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

ENV APP_VERSION 1.0.2
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
        $encryptor = new ObjectEncryptor();

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
                        "composer {{action}}"
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
                        ],
                        [
                            "name" => "action",
                            "type" => "string",
                            "default_value" => "install"
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


    public function testRepositoryPasswordHandling()
    {
        $encryptor = new ObjectEncryptor();
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
                        "{{#password}} {{otherParam}}",
                    ],
                    "entry_point" => "php /home/run.php --data=/data",
                    "parameters" => [
                        [
                            "name" => "#password",
                            "type" => "string"
                        ],
                        [
                            "name" => "otherParam",
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
        /** @var ImageBuilder $image */
        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $image,
            ['parameters' => ['#password' => 'fooBar'], 'runtime' => ["otherParam" => "fox"]]
        );
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        // password in parameters will not be used for repository
        $this->assertEquals('', $image->getRepoPassword());

        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $image,
            ['parameters' => [], 'runtime' => ['#password' => 'fooBar', "otherParam" => "fox"]]
        );
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        try {
            // password in definition will not be used in Dockerfile
            $reflection->invoke($image, $tempDir->getTmpFolder());
            $this->fail("Missing parameter must cause exception.");
        } catch (BuildParameterException $e) {
            $this->assertContains('{{#password}}', $e->getMessage());
            $this->assertNotContains('{{otherParam}}', $e->getMessage());
        }
        $this->assertEquals('fooBar', $image->getRepoPassword());
    }


    public function testDockerFileUndefParameters()
    {
        $encryptor = new ObjectEncryptor();

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
        $encryptor = new ObjectEncryptor();

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
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId(123);
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($wrapper);

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
            'https://' . GIT_PRIVATE_USERNAME . ':' . GIT_PRIVATE_PASSWORD . '@github.com/keboola/docker-demo-app',
            trim($credentials)
        );
    }


    public function testInvalidRepository()
    {
        $encryptor = new ObjectEncryptor();

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


    public function testDockerFileBothParameters()
    {
        // test that both values from parameters and definition are treated equally
        $encryptor = new ObjectEncryptor();
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
                        "{{somewhere}} {{over}} {{the}} {{rainbow}}",
                    ],
                    "entry_point" => "php /home/run.php --data=/data",
                    "parameters" => [
                        [
                            "name" => "somewhere",
                            "type" => "string"
                        ],
                        [
                            "name" => "over",
                            "type" => "string"
                        ],
                        [
                            "name" => "the",
                            "type" => "string"
                        ],
                        [
                            "name" => "rainbow",
                            "type" => "plain_string"
                        ]
                    ]
                ]
            ],
            "configuration_format" => "yaml",
        ];
        $config = [
            'parameters' => [
                'somewhere' => 'quick',
                'over' => 'brown'
            ],
            'runtime' => [
                'the' => 'fox',
                'rainbow' => 'jumped'
            ]
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $config);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $expectedFile = <<<DOCKERFILE
FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Image definition commands
RUN quick brown fox jumped
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data
DOCKERFILE;
        $this->assertEquals($expectedFile, trim($dockerFile));
    }
}
