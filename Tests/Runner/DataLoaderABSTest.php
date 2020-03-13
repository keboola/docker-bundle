<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DataLoaderABSTest extends BaseDataLoaderTest
{
    public function setUp()
    {
        parent::setUp();

        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(["docker-bundle-test"]);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }
    }

    private function getNoDefaultBucketComponent()
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

    public function testLoadInputData()
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                    ],
                ],
                'files' => [['tags' => ['docker-bundle-test']]]
            ],
        ];
        $fs = new Filesystem();
        $filePath = $this->workingDir->getDataDir() . '/upload/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->client->createBucket('docker-demo-testConfig', 'in');
        $this->client->createTable('in.c-docker-demo-testConfig', 'test', new CsvFile($filePath));
        $this->client->uploadFile($filePath, (new FileUploadOptions())->setTags(["docker-bundle-test"]));

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]));

        $manifest = json_decode(
            file_get_contents($this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig.test.manifest'),
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
        $this->assertEquals('in.c-docker-demo-testConfig.test', $manifest['id']);
        $this->assertEquals('test', $manifest['name']);
    }
}
