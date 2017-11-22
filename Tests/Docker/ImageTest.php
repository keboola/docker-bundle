<?php

namespace Keboola\DockerBundle\Tests;

use Defuse\Crypto\Key;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;

class ImageTest extends \PHPUnit_Framework_TestCase
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
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $this->encryptorFactory->setComponentId('keboola.docker-demo-app');
    }

    public function testDockerHub()
    {
        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200,
                "forward_token" => true,
                "forward_token_details" => true,
                "default_bucket" => true,
                "configuration_format" => 'json'
            ]
        ]);

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(DockerHub::class, get_class($image));
        $this->assertEquals("master", $image->getTag());
        $this->assertEquals("keboola/docker-demo:master", $image->getFullImageId());
    }

    public function testDockerHubPrivateRepository()
    {
        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub-private",
                    "uri" => "keboola/docker-demo",
                    "repository" => [
                        "#password" => $this->encryptorFactory->getEncryptor()->encrypt("bb"),
                        "username" => "cc",
                        "server" => "dd"
                    ]
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200
            ]
        ]);
        /** @var DockerHub\PrivateRepository $image */
        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(DockerHub\PrivateRepository::class, get_class($image));
        $this->assertEquals("bb", $image->getLoginPassword());
        $this->assertEquals("cc", $image->getLoginUsername());
        $this->assertEquals("dd", $image->getLoginServer());

        $this->assertEquals("--username='cc' --password='bb' 'dd'", $image->getLoginParams());
        $this->assertEquals("'dd'", $image->getLogoutParams());
        $this->assertEquals("keboola/docker-demo:latest", $image->getFullImageId());
    }

    public function testQuayIO()
    {
        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio",
                    "uri" => "keboola/docker-demo-app"
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200,
                "forward_token" => true,
                "forward_token_details" => true,
                "configuration_format" => 'json'
            ]
        ]);

        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(QuayIO::class, get_class($image));
        $this->assertEquals("quay.io/keboola/docker-demo-app:latest", $image->getFullImageId());
    }


    public function testQuayIOPrivateRepository()
    {
        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "#password" => $this->encryptorFactory->getEncryptor()->encrypt("bb"),
                        "username" => "cc"
                    ]
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200
            ]
        ]);
        /** @var QuayIO\PrivateRepository $image */
        $image = ImageFactory::getImage($this->encryptorFactory->getEncryptor(), new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(QuayIO\PrivateRepository::class, get_class($image));
        $this->assertEquals("bb", $image->getLoginPassword());
        $this->assertEquals("cc", $image->getLoginUsername());
        $this->assertEquals("quay.io", $image->getLoginServer());

        $this->assertEquals("--username='cc' --password='bb' 'quay.io'", $image->getLoginParams());
        $this->assertEquals("'quay.io'", $image->getLogoutParams());
        $this->assertEquals("quay.io/keboola/docker-demo-private:latest", $image->getFullImageId());
    }
}
