<?php

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\DockerBundle\Tests\Docker\ImageTest;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistryTest extends BaseImageTest
{

    public function testMissingCredentials()
    {
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => AWS_ECR_REGISTRY_URI,
                    'repository' => [
                        'region' => AWS_ECR_REGISTRY_REGION,
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->setRetryLimits(100, 100, 1);
        self::expectException(LoginFailedException::class);
        $image->prepare([]);
    }

    public function testInvalidCredentials()
    {
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID . '_invalid');
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => AWS_ECR_REGISTRY_URI,
                    'repository' => [
                        'region' => AWS_ECR_REGISTRY_REGION,
                    ],
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($this->getEncryptor(), $logger, $imageConfig, new Temp(), true);
        $image->setRetryLimits(100, 100, 1);
        try {
            $image->prepare([]);
            self::fail('Must raise an exception');
        } catch (LoginFailedException $e) {
            self::assertContains('Error executing "GetAuthorizationToken"', $e->getMessage());
            self::assertTrue($logger->hasNoticeThatContains('Retrying AWS GetCredentials'));
        }
    }

    public function testDownloadedImage()
    {
        (new Process('sudo docker rmi -f $(sudo docker images -aq ' . AWS_ECR_REGISTRY_URI . ')'))->run();
        $process = new Process('sudo docker images | grep ' . AWS_ECR_REGISTRY_URI . ' | wc -l');
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));
        
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => AWS_ECR_REGISTRY_URI,
                    'repository' => [
                        'region' => AWS_ECR_REGISTRY_REGION,
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);
        self::assertEquals(AWS_ECR_REGISTRY_URI . ':latest', $image->getFullImageId());

        $process = new Process('sudo docker images | grep ' . AWS_ECR_REGISTRY_URI . '| wc -l');
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));
        (new Process('sudo docker rmi ' . AWS_ECR_REGISTRY_URI))->run();
    }

    public function testGetAwsAccountId()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => AWS_ECR_REGISTRY_URI,
                    'repository' => [
                        'region' => AWS_ECR_REGISTRY_REGION,
                    ],
                ],
            ],
        ]);
        /** @var AWSElasticContainerRegistry $image */
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        self::assertEquals(AWS_ECR_REGISTRY_ACCOUNT_ID, $image->getAwsAccountId());
    }

    public function testLogger()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => AWS_ECR_REGISTRY_URI,
                    'repository' => [
                        'region' => AWS_ECR_REGISTRY_REGION,
                    ],
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $testHandler = new TestHandler();
        $logger = new Logger('null', [$testHandler]);
        $image = ImageFactory::getImage($this->getEncryptor(), $logger, $imageConfig, new Temp(), true);
        $image->prepare([]);

        self::assertEquals(AWS_ECR_REGISTRY_URI . ':test-hash', $image->getFullImageId());
        self::assertTrue($testHandler->hasNotice(
            'Using image ' . AWS_ECR_REGISTRY_URI .
            ':test-hash with repo-digest 061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:' .
            ImageTest::TEST_HASH_DIGEST
        ));
        self::assertEquals(
            ['061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:' . ImageTest::TEST_HASH_DIGEST],
            $image->getImageDigests()
        );

        (new Process('sudo docker rmi ' . AWS_ECR_REGISTRY_URI))->run();
    }
}
