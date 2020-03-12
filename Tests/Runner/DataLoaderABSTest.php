<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderABSTest extends BaseDataLoaderTest
{
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

    public function testLoadInputData()
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
            $this->getNoDefaultBucketComponent(),
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]));

        $manifest = json_decode(
            file_get_contents($this->workingDir->getDataDir() . '/in/tables/in.c-docker-demo-testConfig.test.manifest'),
            true
        );

        var_dump($manifest); die;

//        $this->assertS3info($manifest);
    }
}
