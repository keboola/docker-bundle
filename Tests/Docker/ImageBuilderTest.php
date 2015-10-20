<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ImageBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testDockerFile()
    {
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository_type" => "git",
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
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $image = Image::factory($encryptor, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileNotExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $expectedFile =
'FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Repository initialization

# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd /home/
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data';
        $this->assertEquals($expectedFile, trim($dockerFile));
    }


    public function testGitCredentials()
    {
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository_type" => "git",
                    "#password" => $encryptor->encrypt(GIT_PRIVATE_PASSWORD),
                    "username" => GIT_PRIVATE_USERNAME,
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
        $tempDir = new Temp('docker-test');
        $tempDir->initRunFolder();
        $image = Image::factory($encryptor, $imageConfig);
        $reflection = new \ReflectionMethod(ImageBuilder::class, 'createDockerFile');
        $reflection->setAccessible(true);
        $reflection->invoke($image, $tempDir->getTmpFolder());
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $this->assertFileExists($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $dockerFile = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile');
        $expectedFile =
'FROM keboolaprivatetest/docker-demo-docker
WORKDIR /home

# Repository initialization
COPY .git-credentials /tmp/.git-credentials
RUN git config --global credential.helper \'store --file=/tmp/.git-credentials\'

# Image definition commands
RUN git clone https://github.com/keboola/docker-demo-app /home/
RUN cd /home/
RUN composer install
WORKDIR /data
ENTRYPOINT php /home/run.php --data=/data';
        $this->assertEquals($expectedFile, trim($dockerFile));
        $credentials = file_get_contents($tempDir->getTmpFolder() . DIRECTORY_SEPARATOR . '.git-credentials');
        $this->assertEquals(
            'https://keboolaprivatetest:uH11KyDFnG1G8gPHHzmn@github.com/keboola/docker-demo-app',
             trim($credentials)
        );
    }

    public function testInvalidRepository()
    {
        $encryptor = new ObjectEncryptor(new CryptoWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "build_options" => [
                    "repository_type" => "fooBar",
                    "repository" => "https://github.com/keboola/docker-demo-app",
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
        try {
            $image = Image::factory($encryptor, $imageConfig);
            $this->fail("Invalid repository should fail.");
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('Invalid repository_type', $e->getMessage());
        }
    }
}