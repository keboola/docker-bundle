<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class ImageTest extends \PHPUnit_Framework_TestCase
{
    public function testFactory()
    {
        $dummyConfig = array(
            "definition" => array(
                "type" => "dummy",
                "uri" => "dummy"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $dummyConfig);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image", get_class($image));
        $this->assertEquals("64m", $image->getMemory());
        $this->assertEquals(1024, $image->getCpuShares());
        $this->assertEquals('yaml', $image->getConfigFormat());
        $this->assertEquals(false, $image->getForwardToken());
        $this->assertEquals(false, $image->getForwardTokenDetails());
        $this->assertEquals(false, $image->isDefaultBucket());
        $this->assertEquals("latest", $image->getTag());
        $this->assertEquals("dummy:latest", $image->getFullImageId());

        $configuration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo",
                "tag" => "master"
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200,
            "forward_token" => true,
            "forward_token_details" => true,
            "streaming_logs" => true,
            "default_bucket" => true,
            "configuration_format" => 'json'
        );
        $encryptor = new ObjectEncryptor();

        $image = Image::factory($encryptor, $log, $configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\DockerHub", get_class($image));
        $this->assertEquals("128m", $image->getMemory());
        $this->assertEquals(2048, $image->getCpuShares());
        $this->assertEquals(7200, $image->getProcessTimeout());
        $this->assertEquals(true, $image->getForwardToken());
        $this->assertEquals(true, $image->getForwardTokenDetails());
        $this->assertEquals(true, $image->isStreamingLogs());
        $this->assertEquals(true, $image->isDefaultBucket());
        $this->assertEquals('json', $image->getConfigFormat());
        $this->assertEquals("master", $image->getTag());
        $this->assertEquals("keboola/docker-demo:master", $image->getFullImageId());
    }

    public function testDockerHubPrivateRepository()
    {
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId(123);
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($wrapper);

        $configuration = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboola/docker-demo",
                "repository" => array(
                    "email" => "aa",
                    "#password" => $encryptor->encrypt("bb"),
                    "username" => "cc",
                    "server" => "dd"
                )
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200
        );
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        /** @var Image\DockerHub\PrivateRepository $image */
        $image = Image::factory($encryptor, $log, $configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\DockerHub\\PrivateRepository", get_class($image));
        $this->assertEquals("aa", $image->getLoginEmail());
        $this->assertEquals("bb", $image->getLoginPassword());
        $this->assertEquals("cc", $image->getLoginUsername());
        $this->assertEquals("dd", $image->getLoginServer());

        $this->assertEquals("--email='aa' --username='cc' --password='bb' 'dd'", $image->getLoginParams());
        $this->assertEquals("'dd'", $image->getLogoutParams());
        $this->assertEquals("keboola/docker-demo:latest", $image->getFullImageId());
    }

    public function testQuayIO()
    {
        $dummyConfig = array(
            "definition" => array(
                "type" => "quayio",
                "uri" => "dummy"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $dummyConfig);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\QuayIO", get_class($image));
        $this->assertEquals("64m", $image->getMemory());
        $this->assertEquals(1024, $image->getCpuShares());
        $this->assertEquals('yaml', $image->getConfigFormat());
        $this->assertEquals(false, $image->getForwardToken());
        $this->assertEquals(false, $image->getForwardTokenDetails());

        $configuration = array(
            "definition" => array(
                "type" => "quayio",
                "uri" => "keboola/docker-demo-app"
            ),
            "cpu_shares" => 2048,
            "memory" => "128m",
            "process_timeout" => 7200,
            "forward_token" => true,
            "forward_token_details" => true,
            "streaming_logs" => true,
            "configuration_format" => 'json'
        );
        $encryptor = new ObjectEncryptor();

        $image = Image::factory($encryptor, $log, $configuration);
        $this->assertEquals("Keboola\\DockerBundle\\Docker\\Image\\QuayIO", get_class($image));
        $this->assertEquals("128m", $image->getMemory());
        $this->assertEquals(2048, $image->getCpuShares());
        $this->assertEquals(7200, $image->getProcessTimeout());
        $this->assertEquals(true, $image->getForwardToken());
        $this->assertEquals(true, $image->getForwardTokenDetails());
        $this->assertEquals(true, $image->isStreamingLogs());
        $this->assertEquals('json', $image->getConfigFormat());
        $this->assertEquals("quay.io/keboola/docker-demo-app:latest", $image->getFullImageId());
    }

    public function testFormat()
    {
        $dummyConfig = array(
            "definition" => array(
                "type" => "dummy",
                "uri" => "dummy"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $dummyConfig);
        $image->setConfigFormat('yaml');
        $this->assertEquals('yaml', $image->getConfigFormat());
        try {
            $image->setConfigFormat('fooBar');
            $this->fail("Invalid format should cause exception.");
        } catch (\Exception $e) {
        }
    }
}
