<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class DataLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    private function getS3StagingComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "staging-storage" => [
                    "input" => "s3"
                ]
            ]
        ]);
    }

    private function getNoDefaultBucketComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],

            ]
        ]);
    }

    private function getDefaultBucketComponent()
    {
        // use the docker-demo component for testing
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "default_bucket" => true
            ]
        ]);
    }

    public function setUp()
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $files = $this->client->listFiles((new ListFilesOptions())->setTags(['data-loader-test']));
        foreach ($files as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    public function testExecutorDefaultBucket()
    {
        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }

        $temp = new Temp();
        $data = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createWorkingDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            [],
            $this->getDefaultBucketComponent(),
            new OutputFilter(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $this->assertTrue($this->client->tableExists('in.c-docker-demo-whatever.sliced'));

        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }
    }

    public function testNoConfigDefaultBucketException()
    {
        try {
            $temp = new Temp();
            $data = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());
            $data->createWorkingDir();

            new DataLoader(
                $this->client,
                new NullLogger(),
                $data->getDataDir(),
                [],
                $this->getDefaultBucketComponent(),
                new OutputFilter()
            );
            $this->fail("ConfigId is required for defaultBucket=true component data setting");
        } catch (UserException $e) {
            $this->assertStringStartsWith("Configuration ID not set", $e->getMessage());
        }
    }

    public function testExecutorInvalidOutputMapping()
    {
        $config = [
            "input" => [
                "tables" => [
                    [
                        "source" => "in.c-docker-test.test"
                    ]
                ]
            ],
            "output" => [
                "tables" => [
                    [
                        "source" => "sliced.csv",
                        "destination" => "in.c-docker-test.out",
                        // erroneous lines
                        "primary_key" => "col1",
                        "incremental" => 1
                    ]
                ]
            ]
        ];

        $temp = new Temp();
        $data = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createWorkingDir();
        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        try {
            $dataLoader->storeOutput();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testStoreArchive()
    {
        $config = [
            "input" => [
                "tables" => [
                    [
                        "source" => "in.c-docker-test.test"
                    ],
                ],
            ],
            "output" => [
                "tables" => [
                    [
                        "source" => "sliced.csv",
                        "destination" => "in.c-docker-test.out",
                    ],
                ],
            ],
        ];

        $temp = new Temp();
        $data = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createWorkingDir();
        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/in/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        $dataLoader->storeDataArchive('data', ['data-loader-test']);
        $files = $this->client->listFiles((new ListFilesOptions())->setTags(['data-loader-test']));
        self::assertCount(1, $files);

        $temp2 = new Temp();
        $temp2->initRunFolder();
        $target = $temp2->getTmpFolder() . '/tmp-download.zip';
        $this->downloadFile($files[0]['id'], $target);

        $zipArchive = new \ZipArchive();
        $zipArchive->open($target);
        self::assertEquals(8, $zipArchive->numFiles);
        $items = [];
        for ($i = 0; $i < $zipArchive->numFiles; $i++) {
            $items[] = $zipArchive->getNameIndex($i);
            $data = $zipArchive->getFromIndex($i);
            $isSame = is_dir($temp->getTmpFolder() . '/data/' . $zipArchive->getNameIndex($i)) ||
            (string) file_get_contents($temp->getTmpFolder() . '/data/' . $zipArchive->getNameIndex($i)) === (string) $data;
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
        if ($this->client->bucketExists('in.c-docker-test')) {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        }
        $this->client->createBucket('docker-test', Client::STAGE_IN);

        $config = [
            "input" => [
                "tables" => [
                    [
                        "source" => "in.c-docker-test.test",
                    ]
                ]
            ]
        ];

        $temp = new Temp();
        $data = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createWorkingDir();

        $fs = new Filesystem();
        $filePath = $data->getDataDir() . '/in/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->client->createTable('in.c-docker-test', 'test', new CsvFile($filePath));

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getS3StagingComponent(),
            new OutputFilter()
        );
        $dataLoader->loadInputData();

        $manifest = json_decode(
            file_get_contents($data->getDataDir() . '/in/tables/in.c-docker-test.test.manifest'),
            true
        );

        $this->assertS3info($manifest);
    }

    private function assertS3info($manifest)
    {
        $this->assertArrayHasKey("s3", $manifest);
        $this->assertArrayHasKey("isSliced", $manifest["s3"]);
        $this->assertArrayHasKey("region", $manifest["s3"]);
        $this->assertArrayHasKey("bucket", $manifest["s3"]);
        $this->assertArrayHasKey("key", $manifest["s3"]);
        $this->assertArrayHasKey("credentials", $manifest["s3"]);
        $this->assertArrayHasKey("access_key_id", $manifest["s3"]["credentials"]);
        $this->assertArrayHasKey("secret_access_key", $manifest["s3"]["credentials"]);
        $this->assertArrayHasKey("session_token", $manifest["s3"]["credentials"]);
        $this->assertContains(".gz", $manifest["s3"]["key"]);
        if ($manifest["s3"]["isSliced"]) {
            $this->assertContains("manifest", $manifest["s3"]["key"]);
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
                'key' => $fileInfo["credentials"]["AccessKeyId"],
                'secret' => $fileInfo["credentials"]["SecretAccessKey"],
                'token' => $fileInfo["credentials"]["SessionToken"],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject(array(
            'Bucket' => $fileInfo["s3Path"]["bucket"],
            'Key' => $fileInfo["s3Path"]["key"],
            'SaveAs' => $targetFile,
        ));
    }
}
