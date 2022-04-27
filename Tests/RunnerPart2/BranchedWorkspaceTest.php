<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\Temp\Temp;

class BranchedWorkspaceTest extends BaseRunnerTest
{
    const BRANCH_NAME = 'workspace-test';
    const BUCKET_NAME = 'workspace-test';
    const TABLE_NAME = 'test-table';
    const TABLE_DATA = [
        ['id', 'text'],
        ['test1', 'test1'],
    ];

    public function testConfigIsAvailableToJobWhenCreatedBeforeBranch(): void
    {
        $storageApi = $this->getClient();
        $storageApiMaster = $this->createMasterClient();

        // prepare data
        $inBucketId = $this->cleanupAndCreateBucket($storageApi, self::BUCKET_NAME, $storageApi::STAGE_IN);
        $outBucketId = $this->cleanupAndCreateBucket($storageApi, self::BUCKET_NAME, $storageApi::STAGE_OUT);

        $this->loadDataIntoTable($storageApi, $inBucketId, self::TABLE_NAME, self::TABLE_DATA);

        // setup configuration outside branch
        $componentsApiForConfig = new Components($storageApi);
        $configId = $this->generateConfigId();

        $this->cleanupAndCreateConfiguration(
            $componentsApiForConfig,
            (new Configuration())
                ->setComponentId('keboola.runner-workspace-test')
                ->setName($configId)
                ->setConfigurationId($configId)
        );

        // create branch
        $branchesApiMaster = new DevBranches($storageApiMaster);
        $branchId = $this->cleanupAndCreateBranch($branchesApiMaster, self::BRANCH_NAME);
        $storageApiWrapper = new ClientWrapper(new ClientOptions(
            $storageApi->getApiUrl(),
            $storageApi->getTokenString(),
            $branchId
        ));

        // run testing job
        $jobDefinition = $this->createCopyJobDefinition($configId, $inBucketId, $outBucketId, self::TABLE_NAME);
        $this->runJob($storageApiWrapper, $jobDefinition);

        $branchOutBucketId = sprintf('%s.c-%s-%s', $storageApi::STAGE_OUT, $branchId, self::BUCKET_NAME);
        self::assertSame(
            self::TABLE_DATA,
            $this->loadDataFromTable($storageApi, $branchOutBucketId, self::TABLE_NAME)
        );
    }

    public function testBranchConfigIsAvailableToBranchJob()
    {
        $storageApi = $this->getClient();
        $storageApiMaster = $this->createMasterClient();

        // prepare data
        $inBucketId = $this->cleanupAndCreateBucket($storageApi, self::BUCKET_NAME, $storageApi::STAGE_IN);
        $outBucketId = $this->cleanupAndCreateBucket($storageApi, self::BUCKET_NAME, $storageApi::STAGE_OUT);

        $this->loadDataIntoTable($storageApi, $inBucketId, self::TABLE_NAME, self::TABLE_DATA);

        // create branch
        $branchesApiMaster = new DevBranches($storageApiMaster);
        $branchId = $this->cleanupAndCreateBranch($branchesApiMaster, self::BRANCH_NAME);
        $storageApiWrapper = new ClientWrapper(new ClientOptions(
            $storageApi->getApiUrl(),
            $storageApi->getTokenString(),
            $branchId
        ));

        // setup configuration inside branch
        $componentsApiForConfig = new Components($storageApiWrapper->getBranchClient());
        $configId = $this->generateConfigId();

        $this->createConfiguration(
            $componentsApiForConfig,
            (new Configuration())
                ->setComponentId('keboola.runner-workspace-test')
                ->setName('runner-tests')
                ->setConfigurationId($configId)
        );

        // run testing job
        $jobDefinition = $this->createCopyJobDefinition($configId, $inBucketId, $outBucketId, self::TABLE_NAME);
        $this->runJob($storageApiWrapper, $jobDefinition);

        $branchOutBucketId = sprintf('%s.c-%s-%s', $storageApi::STAGE_OUT, $branchId, self::BUCKET_NAME);
        self::assertSame(
            self::TABLE_DATA,
            $this->loadDataFromTable($storageApi, $branchOutBucketId, self::TABLE_NAME)
        );
    }

    private function generateConfigId()
    {
        return uniqid('workspace-test-');
    }

    private function cleanupAndCreateBucket(Client $storageApi, $bucketName, $bucketStage)
    {
        try {
            $bucketId = $storageApi->getBucketId('c-'. $bucketName, $bucketStage);

            if ($bucketId !== false) {
                $storageApi->dropBucket($bucketId, ['force' => true]);
            }
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        return $storageApi->createBucket($bucketName, $bucketStage);
    }

    private function cleanupAndCreateConfiguration(Components $componentsApi, Configuration $configuration)
    {
        try {
            $componentsApi->deleteConfiguration($configuration->getComponentId(), $configuration->getConfigurationId());
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return $this->createConfiguration($componentsApi, $configuration);
    }

    private function createConfiguration(Components $componentsApi, Configuration $configuration)
    {
        return $componentsApi->addConfiguration($configuration)['id'];
    }

    private function cleanupAndCreateBranch(DevBranches $branchesApi, $branchName)
    {
        $branchList = $branchesApi->listBranches();
        foreach ($branchList as $branch) {
            if ($branch['name'] === $branchName) {
                $branchesApi->deleteBranch($branch['id']);
                break;
            }
        }

        return $branchesApi->createBranch($branchName)['id'];
    }

    private function loadDataIntoTable(Client $storageApi, $bucketId, $tableName, array $data)
    {
        $temp = new Temp();
        $temp->initRunFolder();

        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        foreach ($data as $row) {
            $csv->writeRow($row);
        }

        $storageApi->createTableAsync($bucketId, $tableName, $csv);
    }

    private function createCopyJobDefinition($configId, $inBucketId, $outBucketId, $tableName)
    {
        $configData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => $inBucketId.'.'.$tableName,
                            'destination' => 'local-table',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'local-table',
                            'destination' => $outBucketId.'.'.$tableName,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'operation' => 'copy',
            ],
        ];

        $componentData = [
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];

        return new JobDefinition($configData, new Component($componentData), $configId, 'v123', []);
    }

    private function runJob(ClientWrapper $storageApiWrapper, JobDefinition $jobDefinition)
    {
        $runner = new Runner(
            $this->getEncryptorFactory(),
            $storageApiWrapper,
            $this->getLoggersService(),
            new OutputFilter(),
            'dummy',
            ['cpu_count' => 2],
            RUNNER_MIN_LOG_PORT
        );

        $outputs = [];
        $runner->run(
            [$jobDefinition],
            'run',
            'run',
            '123456',
            new NullUsageFile(),
            [],
            $outputs
        );
        return $outputs;
    }

    /**
     * @return Client
     */
    private function createMasterClient()
    {
        return new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN_MASTER,
        ]);
    }

    private function loadDataFromTable(Client $storageApi, $bucketId, $tableName)
    {
        $csvData = $storageApi->getTableDataPreview($bucketId.'.'.$tableName);
        return Client::parseCsv($csvData, false);
    }
}
