<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class DockerHubPrivateRepositoryTest extends BaseImageTest
{
    public function testMissingCredentials()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboolaprivatetest/docker-demo-docker',
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        self::expectException(LoginFailedException::class);
        self::expectExceptionMessage('Login with your Docker ID to push');
        $image->prepare([]);
    }

    public function testInvalidCredentials()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboolaprivatetest/docker-demo-docker',
                    'repository' => [
                        '#password' => $this->getEncryptor()->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                        'username' => DOCKERHUB_PRIVATE_USERNAME . '_invalid',
                        'server' => DOCKERHUB_PRIVATE_SERVER,
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        self::expectException(LoginFailedException::class);
        self::expectExceptionMessage('incorrect username or password');
        $image->prepare([]);
    }

    public function testDownloadedImageEncryptedPassword()
    {
        (new Process('sudo docker rmi -f $(sudo docker images --filter=\'label=com.keboola.docker.runner.origin=builder\' -aq)'))->run();
        (new Process('sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)'))->run();
        $process = new Process('sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l');
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboolaprivatetest/docker-demo-docker',
                    'repository' => [
                        '#password' => $this->getEncryptor()->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                        'username' => DOCKERHUB_PRIVATE_USERNAME,
                        'server' => DOCKERHUB_PRIVATE_SERVER,
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertEquals('keboolaprivatetest/docker-demo-docker:latest', $image->getFullImageId());

        $process = new Process('sudo docker images | grep keboolaprivatetest/docker-demo-docker | wc -l');
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));

        (new Process('sudo docker rmi -f $(sudo docker images -aq keboolaprivatetest/docker-demo-docker)'))->run();
    }
}
