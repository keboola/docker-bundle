<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApiBranch\ClientWrapper as StorageClientWrapper;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DataLoaderABSTest extends BaseDataLoaderTest
{
    public function setUp()
    {
        parent::setUp();
        $this->cleanup('-abs');
    }

    public function testLoadInputData()
    {
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-demo-testConfig-abs.test',
                        ],
                    ],
                    'files' => [['tags' => ['docker-demo-test-abs']]]
                ],
            ],
        ];
        $fs = new Filesystem();
        $filePath = $this->workingDir->getDataDir() . '/upload/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->client->createBucket('docker-demo-testConfig-abs', 'in');
        $this->client->createTable('in.c-docker-demo-testConfig-abs', 'test', new CsvFile($filePath));
        $this->client->uploadFile($filePath, (new FileUploadOptions())->setTags(['docker-demo-test-abs']));
        sleep(1);

        $clientWrapper = new StorageClientWrapper($this->client, null, null, StorageClientWrapper::BRANCH_MAIN);
        $jobDefinition = new JobDefinition($config, $this->getNoDefaultBucketComponent());
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $jobDefinition,
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));

        $manifest = json_decode(
            file_get_contents($this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig-abs.test.manifest'),
            true
        );

        $finder = new Finder();
        $finder->files()->in($this->workingDir->getDataDir() . '/in/files')->notName('*.manifest');

        $this->assertEquals(1, $finder->count());

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            $this->assertEquals(
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
                file_get_contents($file->getPathname())
            );

            $fileManifest = json_decode(file_get_contents($file->getPathname() . '.manifest'), true);

            self::assertArrayHasKey('id', $fileManifest);
            self::assertArrayHasKey('name', $fileManifest);
            self::assertArrayHasKey('created', $fileManifest);
            self::assertArrayHasKey('is_public', $fileManifest);
            self::assertArrayHasKey('is_encrypted', $fileManifest);
            self::assertArrayHasKey('tags', $fileManifest);
            self::assertArrayHasKey('max_age_days', $fileManifest);
            self::assertArrayHasKey('size_bytes', $fileManifest);
            self::assertArrayHasKey('is_sliced', $fileManifest);
        }

        $this->assertArrayHasKey('id', $manifest);
        $this->assertArrayHasKey('name', $manifest);
        $this->assertArrayHasKey('created', $manifest);
        $this->assertArrayHasKey('uri', $manifest);
        $this->assertArrayHasKey('primary_key', $manifest);
        $this->assertEquals('in.c-docker-demo-testConfig-abs.test', $manifest['id']);
        $this->assertEquals('test', $manifest['name']);
    }
}
