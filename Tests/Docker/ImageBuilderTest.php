<?php

namespace Keboola\DockerBundle\Tests;

use Defuse\Crypto\Key;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Log\NullLogger;

class ImageBuilderTest extends TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        $this->encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            substr(hash('sha256', uniqid()), 0, 32),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $this->encryptorFactory->setComponentId('keboola.docker-demo-app');
    }

    public function testDockerFile()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        $reflection = new \ReflectionProperty(ImageBuilder::class, 'parentImage');
        $reflection->setAccessible(true);
        $reflection->setValue($image, 'keboolaprivatetest/docker-demo-docker:latest');
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
FROM keboolaprivatetest/docker-demo-docker:latest
LABEL com.keboola.docker.runner.origin=builder
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
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        $reflection = new \ReflectionProperty(ImageBuilder::class, 'parentImage');
        $reflection->setAccessible(true);
        $reflection->setValue($image, 'keboolaprivatetest/docker-demo-docker:latest');
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
            'FROM keboolaprivatetest/docker-demo-docker:latest
LABEL com.keboola.docker.runner.origin=builder
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
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        $reflection = new \ReflectionProperty(ImageBuilder::class, 'parentImage');
        $reflection->setAccessible(true);
        $reflection->setValue($image, 'keboolaprivatetest/docker-demo-docker:latest');
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
            'FROM keboolaprivatetest/docker-demo-docker:latest
LABEL com.keboola.docker.runner.origin=builder
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
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        /** @var ImageBuilder $image */
        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
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
            self::fail("Missing parameter must cause exception.");
        } catch (BuildParameterException $e) {
            $this->assertContains('{{#password}}', $e->getMessage());
            $this->assertNotContains('{{otherParam}}', $e->getMessage());
        }
        $this->assertEquals('fooBar', $image->getRepoPassword());
    }


    public function testDockerFileUndefParameters()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        try {
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
            $reflection->setAccessible(true);
            $reflection->invoke($image, ['parameters' => ['foo' => 'fooBar', 'bar' => 'baz']]);
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
            $reflection->setAccessible(true);
            $reflection->invoke($image, $tempDir->getTmpFolder());
            self::fail("Missing parameter definition must raise exception.");
        } catch (BuildParameterException $e) {
            $this->assertContains('Orphaned parameter', $e->getMessage());
        }
    }


    public function testDockerFileParametersMissingValue()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        try {
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
            $reflection->setAccessible(true);
            $reflection->invoke($image, ['parameters' => ['foo' => 'fooBar']]);
            $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
            $reflection->setAccessible(true);
            $reflection->invoke($image, $tempDir->getTmpFolder());
            self::fail("Missing value of parameter must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('has no value', $e->getMessage());
        }
    }

    public function testGitCredentials()
    {
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app",
                            "type" => "git",
                            "#password" => $this->encryptorFactory->getEncryptor()->encrypt(GIT_PRIVATE_PASSWORD),
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
            ]
        ]);
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        $reflection = new \ReflectionProperty(ImageBuilder::class, 'parentImage');
        $reflection->setAccessible(true);
        $reflection->setValue($image, 'keboolaprivatetest/docker-demo-docker:latest');
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
FROM keboolaprivatetest/docker-demo-docker:latest
LABEL com.keboola.docker.runner.origin=builder
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

    public function testDockerFileBothParameters()
    {
        // test that both values from parameters and definition are treated equally
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboolaprivatetest/docker-demo-docker",
                    "build_options" => [
                        "parent_type" => "dockerhub-private",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app",
                            "type" => "git",
                        ],
                        "commands" => [
                            "{{somewhere}} {{over}} {{version}} {{the}} {{rainbow}}",
                        ],
                        "entry_point" => "php /home/run.php --data=/data",
                        "parameters" => [
                            [
                                "name" => "somewhere",
                                "type" => "string"
                            ],
                            [
                                "name" => "version",
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
            ]
        ]);
        $config = [
            'parameters' => [
                'somewhere' => 'quick',
                'over' => 'brown'
            ],
            'runtime' => [
                'the' => 'fox',
                'rainbow' => 'jumped',
                'version' => 'master',
            ]
        ];
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();

        /** @var ImageBuilder $image */
        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $imageConfig, $tempDir, true);
        $this->assertInstanceOf(ImageBuilder::class, $image);
        $this->assertTrue($image->getCache(), 'caching should be enabled by default');

        $reflection = new \ReflectionProperty(ImageBuilder::class, 'parentImage');
        $reflection->setAccessible(true);
        $reflection->setValue($image, 'keboolaprivatetest/docker-demo-docker:latest');
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'initParameters');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $config);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertEquals('master', $image->getVersion(), 'version should be set from runtime parameters');
        $this->assertFalse($image->getCache(), 'version set to master should disable caching');

        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $expectedFile = <<<DOCKERFILE
FROM keboolaprivatetest/docker-demo-docker:latest
LABEL com.keboola.docker.runner.origin=builder
WORKDIR /home

ENV APP_VERSION master
# Image definition commands
RUN quick brown master fox jumped
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data
DOCKERFILE;
        $this->assertEquals($expectedFile, trim($dockerFile));
    }
}
