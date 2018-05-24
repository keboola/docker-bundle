<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class QuayIOPrivateRepositoryTest extends BaseImageTest
{
    public function testMissingCredentials()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio-private',
                    'uri' => 'keboola/docker-demo-private',
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        self::expectException(LoginFailedException::class);
        self::expectExceptionMessage('Username: EOF');
        $image->prepare([]);
    }

    public function testInvalidCredentials()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio-private',
                    'uri' => 'keboola/docker-demo-private',
                    'repository' => [
                        'username' => QUAYIO_PRIVATE_USERNAME . '_invalid',
                        '#password' => $this->getEncryptor()->encrypt(QUAYIO_PRIVATE_PASSWORD),
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
        (new Process('sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-demo-private)'))->run();
        $process = new Process('sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l');
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));
        
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio-private',
                    'uri' => 'keboola/docker-demo-private',
                    'repository' => [
                        'username' => QUAYIO_PRIVATE_USERNAME,
                        '#password' => $this->getEncryptor()->encrypt(QUAYIO_PRIVATE_PASSWORD),
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertEquals('quay.io/keboola/docker-demo-private:latest', $image->getFullImageId());

        $process = new Process('sudo docker images | grep quay.io/keboola/docker-demo-private | wc -l');
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));

        (new Process('sudo docker rmi quay.io/keboola/docker-demo-private'))->run();
    }
}
