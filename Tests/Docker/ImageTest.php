<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Process\Process;

class ImageTest extends BaseImageTest
{
    const TEST_HASH_DIGEST = 'a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a';

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

    public function testImageDigestNotPulled()
    {
        $command = new Process('docker rmi 061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing:test-hash');
        $command->run();
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing',
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($this->getEncryptor(), $logger, $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertTrue($logger->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing:test-hash" not found.'
        ));
    }

    public function testImageDigestPulled()
    {

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing',
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($this->getEncryptor(), $logger, $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertFalse($logger->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing:test-hash" not found.'
        ));
    }

    public function testImageDigestInvalid()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing',
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'latest',
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        preg_match('#@sha256:(.*)$#', $image->getImageDigests()[0], $matches);
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing',
                    'digest' => $matches[1],
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($this->getEncryptor(), $logger, $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertTrue($logger->hasNoticeThatContains(
            'Digest "' . $matches[1] . '" for image ' .
            '"061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing:test-hash" not found.'
        ));
    }
}
