<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\AbstractStagingDefinition;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\DataTypeSupport;
use Keboola\JobQueue\JobConfiguration\JobDefinition\State\State;
use Keboola\JobQueue\JobConfiguration\Mapping\InputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\OutputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\WorkspaceProviderFactory;
use Keboola\KeyGenerator\PemKeyCertificateGenerator;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\StagingProvider\InputProviderInitializer;
use Keboola\StagingProvider\OutputProviderInitializer;
use Keboola\StagingProvider\Provider\LocalStagingProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceProvider;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Provider\WorkspaceProviderInterface;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

class DataLoader implements DataLoaderInterface
{
    private readonly ComponentSpecification $component;
    private readonly Configuration $configuration;
    private readonly State $state;
    /** @var non-empty-string|null */
    private readonly ?string $configId;
    private readonly ?string $configRowId;
    private readonly InputStrategyFactory $inputStrategyFactory;
    private readonly OutputStrategyFactory $outputStrategyFactory;
    private readonly InputDataLoader $inputDataLoader;
    private readonly OutputDataLoader $outputDataLoader;

    public function __construct(
        private readonly ClientWrapper $clientWrapper,
        private readonly LoggerInterface $logger,
        private readonly string $dataDirectory,
        JobDefinition $jobDefinition,
    ) {
        $this->configuration = Configuration::fromArray($jobDefinition->getConfiguration());
        $this->state = State::fromArray($jobDefinition->getState());
        $this->component = $jobDefinition->getComponent();
        $this->configId = $jobDefinition->getConfigId();
        $this->configRowId = $jobDefinition->getRowId();

        $stagingStorageInput = $this->component->getInputStagingStorage();
        $stagingStorageOutput = $this->component->getOutputStagingStorage();
        $this->validateStagingSetting($stagingStorageInput, $stagingStorageOutput);

        $this->inputStrategyFactory = new InputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );

        $this->outputStrategyFactory = new OutputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );

        $tokenInfo = $this->clientWrapper->getBranchClient()->verifyToken();

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $componentsApiClient = new Components($this->clientWrapper->getBranchClient());
        $workspacesApiClient = new Workspaces($this->clientWrapper->getBranchClient());

        $workspaceProviderFactory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
            $this->logger,
        );
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        $workspaceProvider = $workspaceProviderFactory->getWorkspaceStaging(
            $stagingStorageInput,
            $this->component,
            $this->configId,
            $this->configuration->runtime?->backend,
            $this->configuration->storage->input->readOnlyStorageAccess,
        );
        $localProviderFactory = new LocalStagingProvider($dataDirectory);

        $inputProviderInitializer = new InputProviderInitializer(
            $this->inputStrategyFactory,
            $workspaceProvider,
            $localProviderFactory,
        );
        $inputProviderInitializer->initializeProviders(
            $stagingStorageInput,
            $tokenInfo,
        );
        $this->inputDataLoader = new InputDataLoader(
            $this->inputStrategyFactory,
            $this->logger,
            'data/in/',
        );

        $outputProviderInitializer = new OutputProviderInitializer(
            $this->outputStrategyFactory,
            $workspaceProvider,
            $localProviderFactory,
        );
        $outputProviderInitializer->initializeProviders(
            $stagingStorageOutput,
            $tokenInfo,
        );
        $this->outputDataLoader = new OutputDataLoader(
            $this->outputStrategyFactory,
            $this->logger,
            'data/out/',
        );
    }

    /**
     * Download source files
     */
    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    ): StorageState {
        $result = $this->inputDataLoader->loadInputData(
            $this->component,
            $this->configuration,
            $this->state,
        );

        return new StorageState(
            inputTableResult: $result->inputTableResult,
            inputFileStateList: $result->inputFileStateList,
        );
    }

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue
    {
        return $this->outputDataLoader->storeOutput(
            $this->component,
            $this->configuration,
            $this->configId,
            $this->configRowId,
            $isFailedJob,
        );
    }

    public function getWorkspaceBackendSize(): ?string
    {
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting()) working only with inputStrategyFactory, but
        // the workspace providers are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceProviderInterface) {
                    continue;
                }

                return $stagingProvider->getBackendSize();
            }
        }

        return null;
    }

    public function getWorkspaceCredentials(): array
    {
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting()) working only with inputStrategyFactory, but
        // the workspace providers are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceProviderInterface) {
                    continue;
                }

                return $stagingProvider->getCredentials();
            }
        }
        return [];
    }

    /**
     * @return iterable<ProviderInterface>
     */
    private function getStagingProviders(AbstractStagingDefinition $stagingDefinition): iterable
    {
        yield $stagingDefinition->getFileDataProvider();
        yield $stagingDefinition->getFileMetadataProvider();
        yield $stagingDefinition->getTableDataProvider();
        yield $stagingDefinition->getTableMetadataProvider();
    }

    private function validateStagingSetting(
        string $stagingStorageInput,
        string $stagingStorageOutput,
    ): void {
        $workspaceTypes = [
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE,
            AbstractStrategyFactory::WORKSPACE_BIGQUERY,
        ];
        if (in_array($stagingStorageInput, $workspaceTypes)
            && in_array($stagingStorageOutput, $workspaceTypes)
            && $stagingStorageInput !== $stagingStorageOutput
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $stagingStorageInput,
                $stagingStorageOutput,
            ));
        }
    }

    public function cleanWorkspace(): void
    {
        $cleanedProviders = [];
        $maps = array_merge(
            $this->inputStrategyFactory->getStrategyMap(),
            $this->outputStrategyFactory->getStrategyMap(),
        );
        foreach ($maps as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof NewWorkspaceProvider) {
                    continue;
                }
                if (in_array($stagingProvider, $cleanedProviders, true)) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
                    $cleanedProviders[] = $stagingProvider;
                } catch (ClientException $e) {
                    if ($e->getCode() === 404) {
                        // workspace is already deleted
                        continue;
                    }
                    // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
                    $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage());
                }
            }
        }
    }

    public function getDataTypeSupport(): DataTypeSupport
    {
        if (!$this->clientWrapper->getToken()->hasFeature('new-native-types')) {
            return DataTypeSupport::NONE;
        }

        return $this->configuration->storage->output->dataTypeSupport ?? $this->component->getDataTypesSupport();
    }
}
