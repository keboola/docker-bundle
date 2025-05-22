<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\State\State;
use Keboola\JobQueue\JobConfiguration\Mapping\DataLoader\InputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\DataLoader\InputDataLoaderFactory;
use Keboola\JobQueue\JobConfiguration\Mapping\DataLoader\OutputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\DataLoader\OutputDataLoaderFactory;
use Keboola\JobQueue\JobConfiguration\Mapping\StagingWorkspace\StagingWorkspaceFacade;
use Keboola\JobQueue\JobConfiguration\Mapping\StagingWorkspace\StagingWorkspaceFactory;
use Keboola\KeyGenerator\PemKeyCertificateGenerator;
use Keboola\StagingProvider\Staging\StagingClass;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Workspace\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\StorageApiToken;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

abstract class BaseDataLoaderTest extends TestCase
{
    protected ClientWrapper $clientWrapper;
    protected WorkingDirectory $workingDir;
    protected Metadata $metadata;
    protected Temp $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN'),
            ),
        );
        $this->metadata = new Metadata($this->clientWrapper->getBasicClient());
        $this->temp = new Temp();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    protected function cleanup($suffix = ''): void
    {
        $this->dropBucket($this->clientWrapper, 'in.c-docker-demo-testConfig' . $suffix);

        $files = $this->clientWrapper->getBasicClient()->listFiles(
            (new ListFilesOptions())->setTags(['docker-demo-test' . $suffix]),
        );
        foreach ($files as $file) {
            $this->clientWrapper->getBasicClient()->deleteFile($file['id']);
        }
    }

    protected static function dropBucket(ClientWrapper $clientWrapper, string $bucketId): void
    {
        $storageApiClient = $clientWrapper->getBasicClient();

        try {
            $storageApiClient->dropBucket($bucketId, ['async' => true, 'force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                return;
            }

            throw $e;
        }
    }

    protected function getInputDataLoader(
        array $storageConfig = [],
        ?ComponentSpecification $component = null,
        ?string $stagingWorkspaceId = null,
        ?ClientWrapper $clientWrapper = null,
    ): InputDataLoader {
        $clientWrapper ??= $this->clientWrapper;

        $workspaceProvider = new WorkspaceProvider(
            new Workspaces($clientWrapper->getBasicClient()),
            new Components($clientWrapper->getBasicClient()),
            new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
        );

        $dataLoaderFactory = new InputDataLoaderFactory(
            $workspaceProvider,
            new NullLogger(),
            $this->workingDir->getDataDir(),
        );

        return $dataLoaderFactory->createInputDataLoader(
            $clientWrapper,
            $component ?? $this->getDefaultBucketComponent(),
            Configuration::fromArray([
                'storage' => $storageConfig,
            ]),
            State::fromArray([]),
            stagingWorkspaceId: $stagingWorkspaceId,
        );
    }

    protected function getOutputDataLoader(
        array $storageConfig = [],
        ?ComponentSpecification $component = null,
        ?ClientWrapper $clientWrapper = null,
        ?string $configId = 'testConfig',
        ?string $configRowId = null,
    ): OutputDataLoader {
        $clientWrapper ??= $this->clientWrapper;

        $workspaceProvider = new WorkspaceProvider(
            new Workspaces($clientWrapper->getBasicClient()),
            new Components($clientWrapper->getBasicClient()),
            new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
        );

        $dataLoaderFactory = new OutputDataLoaderFactory(
            $workspaceProvider,
            new NullLogger(),
            $this->workingDir->getDataDir(),
        );

        return $dataLoaderFactory->createOutputDataLoader(
            $clientWrapper,
            $component ?? $this->getDefaultBucketComponent(),
            Configuration::fromArray([
                'storage' => $storageConfig,
            ]),
            $configId,
            $configRowId,
            stagingWorkspaceId: null, // TODO
        );
    }

    protected function getStagingWorkspaceFacade(
        StorageApiToken $storageApiToken,
        ComponentSpecification $component,
        array $configData = [],
        ?string $configId = null,
        ?ClientWrapper $clientWrapper = null,
    ): StagingWorkspaceFacade {
        $clientWrapper ??= $this->clientWrapper;

        // ensure we're dealing with a component with workspace staging
        self::assertSame(
            StagingClass::Workspace,
            StagingType::from($component->getInputStagingStorage())->getStagingClass(),
        );

        $workspaceProvider = new WorkspaceProvider(
            new Workspaces($clientWrapper->getBranchClient()),
            new Components($clientWrapper->getBranchClient()),
            new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
        );

        $stagingWorkspaceFactory = new StagingWorkspaceFactory(
            $workspaceProvider,
            new NullLogger(),
        );

        $stagingWorkspaceFacade = $stagingWorkspaceFactory->createStagingWorkspaceFacade(
            $storageApiToken,
            $component,
            Configuration::fromArray($configData),
            $configId,
        );

        // factory always returns staging for components with workspace staging
        assert($stagingWorkspaceFacade !== null);

        return $stagingWorkspaceFacade;
    }

    protected function getDefaultBucketComponent(): ComponentSpecification
    {
        // use the docker-demo component for testing
        return new ComponentSpecification([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'default_bucket' => true,
            ],
        ]);
    }

    protected function getNoDefaultBucketComponent(): ComponentSpecification
    {
        return new ComponentSpecification([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],

            ],
        ]);
    }
}
