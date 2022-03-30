<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
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
                STORAGE_API_URL,
                STORAGE_API_TOKEN
            )
        );
        $this->metadata = new Metadata($this->clientWrapper->getBasicClient());
        $this->temp = new Temp();
        $this->temp->initRunFolder();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    protected function cleanup($suffix = ''): void
    {
        try {
            $this->clientWrapper->getBasicClient()->dropBucket(
                'in.c-docker-demo-testConfig' . $suffix,
                ['force' => true]
            );
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $files = $this->clientWrapper->getBasicClient()->listFiles(
            (new ListFilesOptions())->setTags(['docker-demo-test' . $suffix])
        );
        foreach ($files as $file) {
            $this->clientWrapper->getBasicClient()->deleteFile($file['id']);
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
            $configRow
        );
        return new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $jobDefinition,
            new OutputFilter()
        );
    }

    protected function getDefaultBucketComponent(): Component
    {
        // use the docker-demo component for testing
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'default_bucket' => true
            ]
        ]);
    }

    protected function getNoDefaultBucketComponent(): Component
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],

            ]
        ]);
    }
}
