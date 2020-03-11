<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderTest extends BaseDataLoaderTest
{
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

    public function testExecutorDefaultBucket()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $dataLoader = $this->getDataLoader([]);
        $dataLoader->storeOutput();
        self::assertTrue($this->client->tableExists('in.c-docker-demo-testConfig.sliced'));
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
    }

    public function testNoConfigDefaultBucketException()
    {
        self::expectException(UserException::class);
        self::expectExceptionMessage('Configuration ID not set');
        new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            [],
            $this->getDefaultBucketComponent(),
            new OutputFilter()
        );
    }

    public function testExecutorInvalidOutputMapping()
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-demo-testConfig.out',
                        // erroneous lines
                        'primary_key' => 'col1',
                        'incremental' => 1,
                    ],
                ],
            ],
        ];
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        self::expectException(UserException::class);
        self::expectExceptionMessage('Failed to write manifest for table sliced.csv');
        $dataLoader->storeOutput();
    }

    /**
     * @dataProvider invalidStagingProvider
     * @param string $input
     * @param string $output
     * @param string $error
     */
    public function testWorkspaceInvalid($input, $output, $error)
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => $input,
                    'output' => $output,
                ],
            ],
        ]);
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage($error);
        new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            [],
            $component,
            new OutputFilter()
        );
    }

    public function invalidStagingProvider()
    {
        return [
            'snowflake-redshift' => [
                'workspace-snowflake',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-redshift".'
            ],
            'redshift-snowflake' => [
                'workspace-redshift',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-snowflake".'
            ],
            'redshift-local' => [
                'workspace-redshift',
                'local',
                'Component staging setting mismatch - input: "workspace-redshift", output: "local".'
            ],
            'snowflake-local' => [
                'workspace-snowflake',
                'local',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "local".'
            ],
            'local-redshift' => [
                'local',
                'workspace-redshift',
                'Component staging setting mismatch - input: "local", output: "workspace-redshift".'
            ],
            'local-snowflake' => [
                'local',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "local", output: "workspace-snowflake".'
            ],
        ];
    }

    public function testWorkspace()
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);
        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            [],
            $component,
            new OutputFilter()
        );
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['host', 'warehouse', 'database', 'schema', 'user', 'password'], array_keys($credentials));
        self::assertNotEmpty($credentials['user']);
    }

    public function testStoreArchive()
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-demo-testConfig.out',
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
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        $dataLoader->storeDataArchive('data', ['docker-demo-test']);
        sleep(1);
        $files = $this->client->listFiles((new ListFilesOptions())->setTags(['docker-demo-test']));
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
                        'source' => 'in.c-docker-demo-testConfig.test',
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
        $this->client->createBucket('docker-demo-testConfig', 'in');
        $this->client->createTable('in.c-docker-demo-testConfig', 'test', new CsvFile($filePath));

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            $config,
            $this->getS3StagingComponent(),
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]));

        $manifest = json_decode(
            file_get_contents($this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig.test.manifest'),
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
