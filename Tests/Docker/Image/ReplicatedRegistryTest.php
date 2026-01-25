<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReplicatedRegistryTest extends TestCase
{
    private const ECR_URI = '147946154733.dkr.ecr.us-east-1.amazonaws.com/keboola/test-component';
    private const REPLICATED_REGISTRY_URL = 'us-docker.pkg.dev/my-project/my-repo';

    protected function setUp(): void
    {
        parent::setUp();
        // Clear environment variables before each test
        putenv('USE_REPLICATED_REGISTRY');
        putenv('REPLICATED_REGISTRY_URL');
        putenv('REPLICATED_REGISTRY_LOGIN');
        putenv('REPLICATED_REGISTRY_PASSWORD');
    }

    protected function tearDown(): void
    {
        // Clean up environment variables after each test
        putenv('USE_REPLICATED_REGISTRY');
        putenv('REPLICATED_REGISTRY_URL');
        putenv('REPLICATED_REGISTRY_LOGIN');
        putenv('REPLICATED_REGISTRY_PASSWORD');
        parent::tearDown();
    }

    public function testIsEnabledReturnsTrueWhenConfigured(): void
    {
        putenv('USE_REPLICATED_REGISTRY=true');
        putenv('REPLICATED_REGISTRY_URL=' . self::REPLICATED_REGISTRY_URL);

        self::assertTrue(ReplicatedRegistry::isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenNotEnabled(): void
    {
        putenv('REPLICATED_REGISTRY_URL=' . self::REPLICATED_REGISTRY_URL);

        self::assertFalse(ReplicatedRegistry::isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenEnabledFalse(): void
    {
        putenv('USE_REPLICATED_REGISTRY=false');
        putenv('REPLICATED_REGISTRY_URL=' . self::REPLICATED_REGISTRY_URL);

        self::assertFalse(ReplicatedRegistry::isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenUrlNotSet(): void
    {
        putenv('USE_REPLICATED_REGISTRY=true');

        self::assertFalse(ReplicatedRegistry::isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenUrlEmpty(): void
    {
        putenv('USE_REPLICATED_REGISTRY=true');
        putenv('REPLICATED_REGISTRY_URL=');

        self::assertFalse(ReplicatedRegistry::isEnabled());
    }

    public function testGetImageIdTransformsUrl(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        self::assertEquals(
            'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
            $image->getImageId(),
        );
    }

    public function testGetFullImageIdIncludesTag(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                    'tag' => 'v1.0.0',
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        self::assertEquals(
            'us-docker.pkg.dev/my-project/my-repo/keboola/test-component:v1.0.0',
            $image->getFullImageId(),
        );
    }

    public function testGetLoginParamsBuildsCorrectString(): void
    {
        putenv('REPLICATED_REGISTRY_LOGIN=_json_key');
        putenv('REPLICATED_REGISTRY_PASSWORD={"type": "service_account"}');

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        $loginParams = $image->getLoginParams();

        self::assertStringContainsString('--username=', $loginParams);
        self::assertStringContainsString('_json_key', $loginParams);
        self::assertStringContainsString('--password=', $loginParams);
        self::assertStringContainsString('us-docker.pkg.dev', $loginParams);
    }

    public function testGetLoginParamsThrowsWhenLoginNotSet(): void
    {
        putenv('REPLICATED_REGISTRY_PASSWORD=secret');

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        $this->expectException(LoginFailedException::class);
        $this->expectExceptionMessage('REPLICATED_REGISTRY_LOGIN environment variable is not set');
        $image->getLoginParams();
    }

    public function testGetLoginParamsThrowsWhenPasswordNotSet(): void
    {
        putenv('REPLICATED_REGISTRY_LOGIN=user');

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        $this->expectException(LoginFailedException::class);
        $this->expectExceptionMessage('REPLICATED_REGISTRY_PASSWORD environment variable is not set');
        $image->getLoginParams();
    }

    public function testGetLogoutParamsContainsRegistryHost(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            self::REPLICATED_REGISTRY_URL,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        $logoutParams = $image->getLogoutParams();

        self::assertStringContainsString('us-docker.pkg.dev', $logoutParams);
    }

    public function testImageFactoryCreatesReplicatedRegistryWhenEnabled(): void
    {
        putenv('USE_REPLICATED_REGISTRY=true');
        putenv('REPLICATED_REGISTRY_URL=' . self::REPLICATED_REGISTRY_URL);

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);

        self::assertInstanceOf(ReplicatedRegistry::class, $image);
    }

    public function testImageFactoryCreatesEcrWhenNotEnabled(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);

        self::assertNotInstanceOf(ReplicatedRegistry::class, $image);
    }

    public function testGetImageIdWithAcrUrl(): void
    {
        $acrUrl = 'myregistry.azurecr.io';

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = new ReplicatedRegistry(
            $imageConfig,
            new NullLogger(),
            $acrUrl,
            '147946154733.dkr.ecr.us-east-1.amazonaws.com',
        );

        self::assertEquals(
            'myregistry.azurecr.io/keboola/test-component',
            $image->getImageId(),
        );
    }
}
