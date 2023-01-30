<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\S3;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

class DataLoaderS3Test extends BaseDataLoaderTest
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanup('-s3');
    }

    private function getS3StagingComponent(): Component
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 's3',
                ],
            ],
        ]);
    }

    public function testStoreArchive(): void
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig-s3.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-demo-testConfig-s3.out',
                    ],
                ],
            ],
        ];
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/in/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition(['storage' => $config], $this->getNoDefaultBucketComponent()),
            new OutputFilter(10000)
        );
        $dataLoader->storeDataArchive('data', ['docker-demo-test-s3']);
        sleep(1);
        $files = $this->clientWrapper->getBasicClient()->listFiles(
            (new ListFilesOptions())->setTags(['docker-demo-test-s3'])
        );
        self::assertCount(1, $files);

        $temp = new Temp();
        $target = $temp->getTmpFolder() . '/tmp-download.zip';
        $this->downloadFile($files[0]['id'], $target);

        $zipArchive = new ZipArchive();
        $zipArchive->open($target);
        self::assertEquals(8, $zipArchive->numFiles);
        $items = [];
        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $items[] = $zipArchive->getNameIndex($i);
            $data = $zipArchive->getFromIndex($i);
            $isSame = is_dir($this->temp->getTmpFolder() . '/data/' . $zipArchive->getNameIndex($i)) ||
                (string) file_get_contents($this->temp->getTmpFolder() . '/data/' .
                    $zipArchive->getNameIndex($i)) === (string) $data;
            self::assertTrue($isSame, $zipArchive->getNameIndex($i) . ' data: ' . $data);
        }
        sort($items);
        self::assertEquals(
            ['in/', 'in/files/', 'in/tables/', 'in/tables/sliced.csv', 'in/user/', 'out/', 'out/files/', 'out/tables/'],
            $items
        );
    }

    public function testLoadInputDataS3(): void
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig-s3.test',
                    ],
                ],
            ],
        ];
        $fs = new Filesystem();
        $filePath = $this->workingDir->getDataDir() . '/in/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig-s3', 'in');
        $this->clientWrapper->getBasicClient()->createTable(
            'in.c-docker-demo-testConfig-s3',
            'test',
            new CsvFile($filePath)
        );

        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition(['storage' => $config], $this->getS3StagingComponent()),
            new OutputFilter(10000)
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));

        $manifest = json_decode(
            file_get_contents(
                $this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig-s3.test.manifest'
            ),
            true
        );

        $this->assertS3info($manifest);
    }

    private function assertS3info($manifest): void
    {
        self::assertArrayHasKey('s3', $manifest);
        self::assertArrayHasKey('isSliced', $manifest['s3']);
        self::assertArrayHasKey('region', $manifest['s3']);
        self::assertArrayHasKey('bucket', $manifest['s3']);
        self::assertArrayHasKey('key', $manifest['s3']);
        self::assertArrayHasKey('credentials', $manifest['s3']);
        self::assertArrayHasKey('access_key_id', $manifest['s3']['credentials']);
        self::assertArrayHasKey('secret_access_key', $manifest['s3']['credentials']);
        self::assertArrayHasKey('session_token', $manifest['s3']['credentials']);
        self::assertStringContainsString('.gz', $manifest['s3']['key']);
        if ($manifest['s3']['isSliced']) {
            self::assertStringContainsString('manifest', $manifest['s3']['key']);
        }
    }

    private function downloadFile($fileId, $targetFile): void
    {
        $fileInfo = $this->clientWrapper->getBasicClient()->getFile(
            $fileId,
            (new GetFileOptions())->setFederationToken(true)
        );
        // Initialize S3Client with credentials from Storage API
        $s3Client = new S3Client([
            'version' => '2006-03-01',
            'region' => $fileInfo['region'],
            'retries' => $this->clientWrapper->getClientOptionsReadOnly()->getAwsRetries(),
            'credentials' => [
                'key' => $fileInfo['credentials']['AccessKeyId'],
                'secret' => $fileInfo['credentials']['SecretAccessKey'],
                'token' => $fileInfo['credentials']['SessionToken'],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject([
            'Bucket' => $fileInfo['s3Path']['bucket'],
            'Key' => $fileInfo['s3Path']['key'],
            'SaveAs' => $targetFile,
        ]);
    }
}
