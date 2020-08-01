<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

abstract class BaseDataLoaderTest extends TestCase
{
    /**
     * @var Client
     */
    protected $client;
    /**
     * @var WorkingDirectory
     */
    protected $workingDir;
    /**
     * @var Metadata
     */
    protected $metadata;
    /**
     * @var Temp
     */
    protected $temp;

    public function setUp()
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->metadata = new Metadata($this->client);
        $this->temp = new Temp();
        $this->temp->initRunFolder();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    protected function cleanup($suffix = '')
    {
        try {
            $this->client->dropBucket('in.c-docker-demo-testConfig' . $suffix, ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $files = $this->client->listFiles((new ListFilesOptions())->setTags(['docker-demo-test' . $suffix]));
        foreach ($files as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    protected function getDataLoader(array $config, $configRow = null)
    {
        return new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getDefaultBucketComponent(),
            new OutputFilter(),
            'testConfig',
            $configRow
        );
    }

    protected function getDefaultBucketComponent()
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

    protected function getNoDefaultBucketComponent()
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
