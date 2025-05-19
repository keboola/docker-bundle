<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\GCS;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\DockerBundle\Tests\Runner\BackendAssertsTrait;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\Options\FileUploadOptions;
use Psr\Log\NullLogger;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DataLoaderGCSTest extends BaseDataLoaderTest
{
    use BackendAssertsTrait;

    public function setUp(): void
    {
        parent::setUp();

        self::assertFileBackend('gcp', $this->clientWrapper->getBasicClient());
        $this->cleanup('-gcs');
    }

    public function testLoadInputData()
    {
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-demo-testConfig-gcs.test',
                        ],
                    ],
                    'files' => [['tags' => ['docker-demo-test-gcs']]],
                ],
            ],
        ];
        $fs = new Filesystem();
        $filePath = $this->workingDir->getDataDir() . '/upload/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
        );

        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig-gcs', 'in');
        $this->clientWrapper->getBasicClient()->createTable(
            'in.c-docker-demo-testConfig-gcs',
            'test',
            new CsvFile($filePath),
        );
        $this->clientWrapper->getBasicClient()->uploadFile(
            $filePath,
            (new FileUploadOptions())->setTags(['docker-demo-test-gcs']),
        );
        sleep(1);

        $jobDefinition = new JobDefinition($config, $this->getNoDefaultBucketComponent());
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $jobDefinition,
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));

        $manifest = json_decode(
            file_get_contents(
                $this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig-gcs.test.manifest',
            ),
            true,
        );

        $finder = new Finder();
        $finder->files()->in($this->workingDir->getDataDir() . '/in/files')->notName('*.manifest');

        $this->assertEquals(1, $finder->count());

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $this->assertEquals(
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
                file_get_contents($file->getPathname()),
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
        $this->assertEquals('in.c-docker-demo-testConfig-gcs.test', $manifest['id']);
        $this->assertEquals('test', $manifest['name']);
    }
}
