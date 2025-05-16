<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

abstract class BaseDataLoaderTest extends TestCase
{
    protected ClientWrapper $clientWrapper;
    protected WorkingDirectory $workingDir;
    protected Metadata $metadata;
    protected Temp $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN'),
            ),
        );
        $this->metadata = new Metadata($this->clientWrapper->getBasicClient());
        $this->temp = new Temp();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    protected function cleanup($suffix = ''): void
    {
        $this->dropBucket($this->clientWrapper, 'in.c-docker-demo-testConfig' . $suffix);
        ;

        $files = $this->clientWrapper->getBasicClient()->listFiles(
            (new ListFilesOptions())->setTags(['docker-demo-test' . $suffix]),
        );
        foreach ($files as $file) {
            $this->clientWrapper->getBasicClient()->deleteFile($file['id']);
        }
    }

    protected static function dropBucket(ClientWrapper $clientWrapper, string $bucketId): void
    {
        $storageApiClient = $clientWrapper->getBasicClient();

        try {
            $storageApiClient->dropBucket($bucketId, ['async' => true, 'force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                return;
            }

            throw $e;
        }
    }

    protected function getDataLoader(array $storageConfig, $configRow = null): DataLoader
    {
        $config = ['storage' => $storageConfig];
        $jobDefinition = new JobDefinition(
            $config,
            $this->getDefaultBucketComponent(),
            'testConfig',
            null,
            [],
            $configRow,
        );
        return new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $jobDefinition,
        );
    }

    protected function getDefaultBucketComponent(): ComponentSpecification
    {
        // use the docker-demo component for testing
        return new ComponentSpecification([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'default_bucket' => true,
            ],
        ]);
    }

    protected function getNoDefaultBucketComponent(): ComponentSpecification
    {
        return new ComponentSpecification([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],

            ],
        ]);
    }
}
