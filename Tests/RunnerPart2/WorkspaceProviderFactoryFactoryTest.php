<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Psr\Log\Test\TestLogger;

class WorkspaceProviderFactoryFactoryTest extends BaseDataLoaderTest
{
    public function testReadonlyWorkspaceCreation()
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
        $config = [
            'storage' => [
                'input' => [
                    'read_only_storage_access' => true,
                    'tables' => [],
                    'files' => [],
                ]
            ]
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(STORAGE_API_URL, STORAGE_API_TOKEN_FEATURE_NATIVE_TYPES)
        );
        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        self::assertTrue($logger->hasInfoThatContains('Created a new readonly workspace.'));

        $workspaces = new Workspaces($clientWrapper->getBranchClientIfAvailable());

        $createdWorkspaceId = $dataLoader->getWorkspaceId();

        $workspaceDetails = $workspaces->getWorkspace($createdWorkspaceId);

        self::assertTrue($workspaceDetails['readOnlyStorageAccess']);

        $dataLoader->cleanWorkspace();
    }

    public function testEphemeralWorkspaceCreation()
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
        $config = [
            'storage' => [
                'input' => [
                    'read_only_storage_access' => false,
                    'tables' => [],
                    'files' => [],
                ]
            ]
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(STORAGE_API_URL, STORAGE_API_TOKEN_FEATURE_NATIVE_TYPES)
        );
        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        self::assertTrue($logger->hasInfoThatContains('Created a new ephemeral workspace.'));

        $workspaces = new Workspaces($clientWrapper->getBranchClientIfAvailable());

        $createdWorkspaceId = $dataLoader->getWorkspaceId();

        $workspaceDetails = $workspaces->getWorkspace($createdWorkspaceId);

        self::assertFalse($workspaceDetails['readOnlyStorageAccess']);

        $dataLoader->cleanWorkspace();
    }
}
