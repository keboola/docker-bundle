<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\OAuthV2Api\ClientWrapper;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApiBranch\ClientWrapper as StorageClientWrapper;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderS3Test extends BaseDataLoaderTest
{
    public function setUp()
    {
        parent::setUp();
        $this->cleanup('-s3');
    }

    private function getS3StagingComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 's3'
                ]
            ]
        ]);
    }

    public function testStoreArchive()
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
        $clientWrapper = new StorageClientWrapper($this->client, null, null);
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        $dataLoader->storeDataArchive('data', ['docker-demo-test-s3']);
        sleep(1);
        $files = $this->client->listFiles((new ListFilesOptions())->setTags(['docker-demo-test-s3']));
        self::assertCount(1, $files);

        $temp = new Temp();
        $temp->initRunFolder();
        $target = $temp->getTmpFolder() . '/tmp-download.zip';
        $this->downloadFile($files[0]['id'], $target);

        $zipArchive = new \ZipArchive();
        $zipArchive->open($target);
        self::assertEquals(8, $zipArchive->numFiles);
        $items = [];
        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $items[] = $zipArchive->getNameIndex($i);
            $data = $zipArchive->getFromIndex($i);
            $isSame = is_dir($this->temp->getTmpFolder() . '/data/' . $zipArchive->getNameIndex($i)) ||
                (string) file_get_contents($this->temp->getTmpFolder() . '/data/' . $zipArchive->getNameIndex($i)) === (string) $data;
            self::assertTrue($isSame, $zipArchive->getNameIndex($i) . ' data: ' . $data);
        }
        sort($items);
        self::assertEquals(
            ['in/', 'in/files/', 'in/tables/', 'in/tables/sliced.csv', 'in/user/', 'out/', 'out/files/', 'out/tables/'],
            $items
        );
    }

    public function testLoadInputDataS3()
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
        $this->client->createBucket('docker-demo-testConfig-s3', 'in');
        $this->client->createTable('in.c-docker-demo-testConfig-s3', 'test', new CsvFile($filePath));

        $clientWrapper = new StorageClientWrapper($this->client, null, null);
        $clientWrapper->setBranchId('');
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getS3StagingComponent(),
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]));

        $manifest = json_decode(
            file_get_contents($this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig-s3.test.manifest'),
            true
        );

        $this->assertS3info($manifest);
    }

    private function assertS3info($manifest)
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
        self::assertContains('.gz', $manifest['s3']['key']);
        if ($manifest['s3']['isSliced']) {
            self::assertContains('manifest', $manifest['s3']['key']);
        }
    }

    private function downloadFile($fileId, $targetFile)
    {
        $fileInfo = $this->client->getFile($fileId, (new GetFileOptions())->setFederationToken(true));
        // Initialize S3Client with credentials from Storage API
        $s3Client = new S3Client([
            'version' => '2006-03-01',
            'region' => $fileInfo['region'],
            'retries' => $this->client->getAwsRetries(),
            'credentials' => [
                'key' => $fileInfo['credentials']['AccessKeyId'],
                'secret' => $fileInfo['credentials']['SecretAccessKey'],
                'token' => $fileInfo['credentials']['SessionToken'],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject(array(
            'Bucket' => $fileInfo['s3Path']['bucket'],
            'Key' => $fileInfo['s3Path']['key'],
            'SaveAs' => $targetFile,
        ));
    }
}
