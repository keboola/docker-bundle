<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;

class ImageTest extends BaseImageTest
{
    public function testDockerHub()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        self::assertEquals(DockerHub::class, get_class($image));
        self::assertEquals('master', $image->getTag());
        self::assertEquals('keboola/docker-demo:master', $image->getFullImageId());
    }

    public function testDockerHubPrivateRepository()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboola/docker-demo',
                    'repository' => [
                        '#password' => $this->getEncryptor()->encrypt('bb'),
                        'username' => 'cc',
                        'server' => 'dd'
                    ],
                ],
            ],
        ]);
        /** @var DockerHub\PrivateRepository $image */
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        self::assertEquals(DockerHub\PrivateRepository::class, get_class($image));
        self::assertEquals('bb', $image->getLoginPassword());
        self::assertEquals('cc', $image->getLoginUsername());
        self::assertEquals('dd', $image->getLoginServer());

        self::assertEquals("--username='cc' --password='bb' 'dd'", $image->getLoginParams());
        self::assertEquals("'dd'", $image->getLogoutParams());
        self::assertEquals('keboola/docker-demo:latest', $image->getFullImageId());
    }

    public function testQuayIO()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
            ],
        ]);

        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        self::assertEquals(QuayIO::class, get_class($image));
        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $image->getFullImageId());
    }

    public function testQuayIOPrivateRepository()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio-private',
                    'uri' => 'keboola/docker-demo-private',
                    'repository' => [
                        '#password' => $this->getEncryptor()->encrypt('bb'),
                        'username' => 'cc'
                    ],
                ],
            ],
        ]);
        /** @var QuayIO\PrivateRepository $image */
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        self::assertEquals(QuayIO\PrivateRepository::class, get_class($image));
        self::assertEquals('bb', $image->getLoginPassword());
        self::assertEquals('cc', $image->getLoginUsername());
        self::assertEquals('quay.io', $image->getLoginServer());

        self::assertEquals("--username='cc' --password='bb' 'quay.io'", $image->getLoginParams());
        self::assertEquals("'quay.io'", $image->getLogoutParams());
        self::assertEquals('quay.io/keboola/docker-demo-private:latest', $image->getFullImageId());
    }
}
