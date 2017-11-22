<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
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
        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\LoginFailedException
     */
    public function testInvalidCredentials()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID . "_invalid");
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
    }

    /**
     * Try to download image
     */
    public function testDownloadedImage()
    {
        (new Process("sudo docker rmi -f $(sudo docker images -aq " . AWS_ECR_REGISTRY_URI . ")"))->run();

        $process = new Process("sudo docker images | grep " . AWS_ECR_REGISTRY_URI . " | wc -l");
        $process->run();
        $this->assertEquals(0, trim($process->getOutput()));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals(AWS_ECR_REGISTRY_URI . ":latest", $image->getFullImageId());

        $process = new Process("sudo docker images | grep " . AWS_ECR_REGISTRY_URI . "| wc -l");
        $process->run();
        $this->assertEquals(1, trim($process->getOutput()));

        (new Process("sudo docker rmi " . AWS_ECR_REGISTRY_URI))->run();
    }

    public function testGetAwsAccountId()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ]
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        /** @var AWSElasticContainerRegistry $image */
        $image = ImageFactory::getImage($encryptor, new NullLogger(), $imageConfig, new Temp(), true);
        $this->assertEquals(AWS_ECR_REGISTRY_ACCOUNT_ID, $image->getAwsAccountId());
    }

    public function testLogger()
    {
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        $imageConfig = new Component([
            "data" => [
                "definition" => [
                    "type" => "aws-ecr",
                    "uri" => AWS_ECR_REGISTRY_URI,
                    "repository" => [
                        "region" => AWS_ECR_REGISTRY_REGION
                    ],
                    "tag" => "test-hash"
                ],
                "cpu_shares" => 1024,
                "memory" => "64m",
                "configuration_format" => "json"
            ]
        ]);
        $testHandler = new TestHandler();
        $logger = new Logger('null', [$testHandler]);
        $image = ImageFactory::getImage($encryptor, $logger, $imageConfig, new Temp(), true);
        $image->prepare([]);

        $this->assertEquals(AWS_ECR_REGISTRY_URI . ":test-hash", $image->getFullImageId());
        $this->assertTrue($testHandler->hasNotice(
            'Using image ' . AWS_ECR_REGISTRY_URI .
            ':test-hash with repo-digest 061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:fb3e9bf1a33228e1ddd61eb7df4961b617dea6108b15011029836c431ec74f63'
        ));
        $this->assertEquals(
            ['061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:fb3e9bf1a33228e1ddd61eb7df4961b617dea6108b15011029836c431ec74f63'],
            $image->getImageDigests()
        );

        (new Process("sudo docker rmi " . AWS_ECR_REGISTRY_URI))->run();
    }

    public function tearDown()
    {
        // remove env variables
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        parent::tearDown();
    }
}
