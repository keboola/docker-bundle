<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistryTest extends KernelTestCase
{
    public function setUp()
    {
        self::bootKernel();
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testMissingCredentials()
    {
        $imageConfig = [
            "definition" => [
                "type" => "aws-ecr",
                "uri" => AWS_ECR_REGISTRY_URI
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json"
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testInvalidCredentials()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $imageConfig = array(
            "definition" => array(
                "type" => "aws-ecr",
                "uri" => AWS_ECR_REGISTRY_URI,
                "repository" => [
                    "aws_access_key_id" => AWS_ECR_ACCESS_KEY_ID . "_invalid",
                    "#aws_secret_access_key" => $encryptor->encrypt(AWS_ECR_SECRET_ACCESS_KEY)
                ]

            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json"
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);
    }


    /**
     * Try do download private image using credentials
     */
    public function testDownloadedImageEncryptedPassword()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq " . AWS_ECR_REGISTRY_URI . ")"))->run();

        $process = new Process("sudo docker images | grep " . AWS_ECR_REGISTRY_URI . " | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $imageConfig = [
            "definition" => [
                "type" => "aws-ecr",
                "uri" => AWS_ECR_REGISTRY_URI,
                "repository" => [
                    "aws_access_key_id" => AWS_ECR_ACCESS_KEY_ID,
                    "#aws_secret_access_key" => $encryptor->encrypt(AWS_ECR_SECRET_ACCESS_KEY)
                ]
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json"
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig, true);
        $image->prepare([]);

        $this->assertEquals(AWS_ECR_REGISTRY_URI . ":latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep " . AWS_ECR_REGISTRY_URI . "| wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi " . AWS_ECR_REGISTRY_URI))->run();
    }
}
