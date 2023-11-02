<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\BigQuery;

use Generator;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderBigQueryTest extends BaseDataLoaderTest
{
    private const COMPONENT_ID = 'keboola.runner-workspace-bigquery-test';

    public function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    public function testWorkspaceBigQueryNoPreserve(): void
    {
        $bucketName = 'testWorkspaceBigQueryNoPreserve';
        try {
            $buckets = $this->clientWrapper->getBasicClient()->listBuckets();
            foreach ($buckets as $bucket) {
                if ($bucket['stage'] === 'in' && $bucket['displayName'] === $bucketName) {
                    $this->clientWrapper->getBasicClient()->dropBucket(
                        $bucket['id'],
                        ['force' => true, 'async' => true],
                    );
                }
            }
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $bucketId = $this->clientWrapper->getBasicClient()->createBucket(
            $bucketName,
            'in',
            'description',
            'bigquery',
        );
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
        );
        $csv = new CsvFile($this->temp->getTmpFolder() . '/data.csv');
        $this->clientWrapper->getBasicClient()->createTableAsync($bucketId, 'test', $csv);

        $component = new Component([
            'id' => self::COMPONENT_ID,
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-workspace-test',
                    'tag' => '1.7.1',
                ],
                'staging-storage' => [
                    'input' => 'workspace-bigquery',
                    'output' => 'workspace-bigquery',
                ],
            ],
        ]);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => sprintf('%s.test', $bucketId),
                            'destination' => 'test',
                            'keep_internal_timestamp_column' => false,
                        ],
                    ],
                ],
            ],
        ];
        $configuration = new Configuration();
        $configuration->setName('testWorkspaceBigQueryPreserve');
        $configuration->setComponentId(self::COMPONENT_ID);
        $configuration->setConfiguration($config);
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        // create bigquery workspace and load a table into it
        $workspace = $componentsApi->createConfigurationWorkspace(
            self::COMPONENT_ID,
            $configId,
            ['backend' => 'bigquery'],
            true,
        );
        $workspaceApi = new Workspaces($this->clientWrapper->getBasicClient());
        $workspaceApi->loadWorkspaceData(
            $workspace['id'],
            [
                'input' => [
                    [
                        'source' => sprintf('%s.test', $bucketId),
                        'destination' => 'original',
                        'useView' => true,
                    ],
                ],
            ],
        );

        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component, $configId),
            new OutputFilter(10000),
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['schema', 'credentials'], array_keys($credentials));
        self::assertNotEmpty($credentials['credentials']);

        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId(self::COMPONENT_ID)
                ->setConfigurationId($configId),
        );
        // workspace is not reused so another one was created
        self::assertCount(2, $workspaces);

        // the workspace is not reused and not the same
        self::assertNotSame($workspace['connection']['schema'], $credentials['schema']);

        // but the original table does exists (workspace was not cleared)
        try {
            $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
                sprintf('%s.test', $bucketId),
                ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'original'],
            );
            self::fail('Must throw exception');
        } catch (ClientException $e) {
            self::assertStringContainsString(
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                'Invalid columns: _timestamp: Only alphanumeric characters and underscores are allowed in column name. Underscore is not allowed on the beginning',
                $e->getMessage(),
            );
        }

        try {
            // the loaded table exists, but can not be loaded because of _timestamp column
            $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
                sprintf('%s.test', $bucketId),
                ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'original'],
            );
            self::fail('Must throw exception');
        } catch (ClientException $e) {
            self::assertStringContainsString(
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                'Invalid columns: _timestamp: Only alphanumeric characters and underscores are allowed in column name. Underscore is not allowed on the beginning',
                $e->getMessage(),
            );
        }

        $workspaceApi->deleteWorkspace($workspace['id'], async: true);
    }
}
