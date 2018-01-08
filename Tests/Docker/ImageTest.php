<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;

class ImageTest extends \PHPUnit_Framework_TestCase
{
    public function testDockerHub()
    {
        $encryptor = new ObjectEncryptor();
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(DockerHub::class, get_class($image));
        $this->assertEquals("master", $image->getTag());
        $this->assertEquals("keboola/docker-demo:master", $image->getFullImageId());
    }

    public function testDockerHubPrivateRepository()
    {
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId(123);
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "dockerhub-private",
                    "uri" => "keboola/docker-demo",
                    "repository" => [
                        "#password" => $encryptor->encrypt("bb"),
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
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $configuration, new Temp(), true);
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
        $encryptor = new ObjectEncryptor();
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

        $image = ImageFactory::getImage($encryptor, new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(QuayIO::class, get_class($image));
        $this->assertEquals("quay.io/keboola/docker-demo-app:latest", $image->getFullImageId());
    }


    public function testQuayIOPrivateRepository()
    {
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId(123);
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $configuration = new Component([
            "data" => [
                "definition" => [
                    "type" => "quayio-private",
                    "uri" => "keboola/docker-demo-private",
                    "repository" => [
                        "#password" => $encryptor->encrypt("bb"),
                        "username" => "cc"
                    ]
                ],
                "cpu_shares" => 2048,
                "memory" => "128m",
                "process_timeout" => 7200
            ]
        ]);
        /** @var QuayIO\PrivateRepository $image */
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $configuration, new Temp(), true);
        $this->assertEquals(QuayIO\PrivateRepository::class, get_class($image));
        $this->assertEquals("bb", $image->getLoginPassword());
        $this->assertEquals("cc", $image->getLoginUsername());
        $this->assertEquals("quay.io", $image->getLoginServer());

        $this->assertEquals("--username='cc' --password='bb' 'quay.io'", $image->getLoginParams());
        $this->assertEquals("'quay.io'", $image->getLogoutParams());
        $this->assertEquals("quay.io/keboola/docker-demo-private:latest", $image->getFullImageId());
    }
}
