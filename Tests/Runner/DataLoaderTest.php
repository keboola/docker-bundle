<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderTest extends BaseDataLoaderTest
{
    public function setUp()
    {
        parent::setUp();
        $this->cleanup();
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
            'local-synapse' => [
                'local',
                'workspace-synapse',
                'Component staging setting mismatch - input: "local", output: "workspace-synapse".'
            ],
            'snowflake-synapse' => [
                'workspace-snowflake',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-synapse".'
            ],
            'redshift-synapse' => [
                'workspace-redshift',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-synapse".'
            ],
            'synapse-local' => [
                'workspace-synapse',
                'local',
                'Component staging setting mismatch - input: "workspace-synapse", output: "local".'
            ],
            'synapse-snowflake' => [
                'workspace-synapse',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-snowflake".'
            ],
            'synapse-redshift' => [
                'workspace-synapse',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-redshift".'
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
}
