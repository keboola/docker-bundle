<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\AbstractStagingDefinition;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\JobQueue\JobConfiguration\Component;
use Keboola\JobQueue\JobConfiguration\Configuration\Configuration;
use Keboola\JobQueue\JobConfiguration\Mapping\InputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\OutputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\WorkspaceProviderFactoryFactory;
use Keboola\JobQueue\JobConfiguration\State\State;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\StagingProvider\InputProviderInitializer;
use Keboola\StagingProvider\OutputProviderInitializer;
use Keboola\StagingProvider\Provider\WorkspaceStagingProvider;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

class DataLoader implements DataLoaderInterface
{
    private readonly Component $component;
    private readonly Configuration $jobConfiguration;
    private readonly array $tokenInfo;

    private readonly InputDataLoader $inputDataLoader;
    private readonly OutputDataLoader $outputDataLoader;
    private readonly InputStrategyFactory $inputStrategyFactory;
    private readonly OutputStrategyFactory $outputStrategyFactory;

    public function __construct(
        private readonly ClientWrapper $clientWrapper,
        private readonly LoggerInterface $logger,
        private readonly string $dataDirectory,
        private readonly JobDefinition $jobDefinition,
        private readonly OutputFilterInterface $outputFilter,
    ) {
        $this->component = new Component($jobDefinition->getComponent()->toArray());
        $this->jobConfiguration = Configuration::fromArray($jobDefinition->getConfiguration());

        $component = $this->component;
        $stagingInputStorage = $component->getInputStagingStorage();
        $stagingOutputStorage = $component->getOutputStagingStorage();

        $this->validateStagingSetting($stagingInputStorage, $stagingOutputStorage);

        $this->inputStrategyFactory = new InputStrategyFactory(
            $clientWrapper,
            $logger,
            $component->getConfigurationFormat()
        );

        $this->outputStrategyFactory = new OutputStrategyFactory(
            $clientWrapper,
            $logger,
            $component->getConfigurationFormat()
        );

        $this->tokenInfo = $clientWrapper->getBranchClientIfAvailable()->verifyToken();

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $aboveDataDirectory = dirname($dataDirectory);

        $workspaceProviderFactoryFactory = new WorkspaceProviderFactoryFactory(
            new Components($clientWrapper->getBranchClientIfAvailable()),
            new Workspaces($clientWrapper->getBranchClientIfAvailable()),
            $logger
        );
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        $workspaceProviderFactory = $workspaceProviderFactoryFactory->getWorkspaceProviderFactory(
            $stagingInputStorage,
            $component,
            $jobDefinition->getConfigId() ?: null,
            $this->jobConfiguration->runtime?->backend,
            $this->jobConfiguration->storage->input->readOnlyStorageAccess,
        );
        $inputProviderInitializer = new InputProviderInitializer(
            $this->inputStrategyFactory,
            $workspaceProviderFactory,
            $aboveDataDirectory
        );
        $inputProviderInitializer->initializeProviders(
            $stagingInputStorage,
            $this->tokenInfo
        );

        $outputProviderInitializer = new OutputProviderInitializer(
            $this->outputStrategyFactory,
            $workspaceProviderFactory,
            $aboveDataDirectory
        );
        $outputProviderInitializer->initializeProviders(
            $stagingOutputStorage,
            $this->tokenInfo
        );

        $this->inputDataLoader = new InputDataLoader(
            $this->inputStrategyFactory,
            $logger,
            'data/in',
        );

        $this->outputDataLoader = new OutputDataLoader(
            $this->outputStrategyFactory,
            $logger,
            'data/out',
        );
    }

    private function validateStagingSetting(string $stagingStorageInput, string $stagingStorageOutput): void
    {
        $workspaceTypes = [AbstractStrategyFactory::WORKSPACE_ABS, AbstractStrategyFactory::WORKSPACE_REDSHIFT,
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE, AbstractStrategyFactory::WORKSPACE_SYNAPSE];
        if (in_array($stagingStorageInput, $workspaceTypes)
            && in_array($stagingStorageOutput, $workspaceTypes)
            && $stagingStorageInput !== $stagingStorageOutput
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $stagingStorageInput,
                $stagingStorageOutput
            ));
        }
    }

    public function loadInputData(): StorageState
    {
        $result = $this->inputDataLoader->loadInputData(
            $this->component,
            $this->jobConfiguration,
            State::fromArray($this->jobDefinition->getState()),
        );

        return new StorageState(
            $result->inputTableResult,
            $result->inputFileStateList,
        );
    }

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue
    {
        return $this->outputDataLoader->storeOutput(
            $this->component,
            $this->jobConfiguration,
            $this->clientWrapper->getBranchId(),
            $this->clientWrapper->getBranchClientIfAvailable()->getRunId(),
            $this->jobDefinition->getConfigId(),
            $this->jobDefinition->getRowId(),
            $this->tokenInfo['owner']['features'] ?? [],
            $isFailedJob,
        );
    }

    public function getWorkspaceCredentials(): array
    {
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting()) working only with inputStrategyFactory, but
        // the workspace providers are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
                    continue;
                }

                return $stagingProvider->getCredentials();
            }
        }
        return [];
    }

    /**
     * @return iterable<null|ProviderInterface>
     */
    private function getStagingProviders(AbstractStagingDefinition $stagingDefinition): iterable
    {
        yield $stagingDefinition->getFileDataProvider();
        yield $stagingDefinition->getFileMetadataProvider();
        yield $stagingDefinition->getTableDataProvider();
        yield $stagingDefinition->getTableMetadataProvider();
    }

    /**
     * Archive data directory and save it to Storage
     */
    public function storeDataArchive(string $fileName, array $tags): void
    {
        $zip = new ZipArchive();
        $zipFileName = $this->dataDirectory . DIRECTORY_SEPARATOR . $fileName . '.zip';
        $zip->open($zipFileName, ZipArchive::CREATE);
        $finder = new Finder();
        /** @var SplFileInfo $item */
        foreach ($finder->in($this->dataDirectory) as $item) {
            if ($item->isDir()) {
                if (!$zip->addEmptyDir($item->getRelativePathname())) {
                    throw new ApplicationException('Failed to add directory: ' . $item->getFilename());
                }
            } else {
                if ($item->getPathname() === $zipFileName) {
                    continue;
                }
                if (($item->getRelativePathname() === 'config.json') ||
                    ($item->getRelativePathname() === 'state.json')
                ) {
                    $configData = (string) file_get_contents($item->getPathname());
                    $configData = $this->outputFilter->filter($configData);
                    if (!$zip->addFromString($item->getRelativePathname(), $configData)) {
                        throw new ApplicationException('Failed to add file: ' . $item->getFilename());
                    }
                } elseif (!$zip->addFile($item->getPathname(), $item->getRelativePathname())) {
                    throw new ApplicationException('Failed to add file: ' . $item->getFilename());
                }
            }
        }
        $zip->close();
        $uploadOptions = new FileUploadOptions();
        $uploadOptions->setTags($tags);
        $uploadOptions->setIsPermanent(false);
        $uploadOptions->setIsPublic(false);
        $uploadOptions->setNotify(false);
        $this->clientWrapper->getBasicClient()->uploadFile($zipFileName, $uploadOptions);
        $fs = new Filesystem();
        $fs->remove($zipFileName);
    }

    public function cleanWorkspace(): void
    {
        $cleanedProviders = [];
        $maps = array_merge(
            $this->inputStrategyFactory->getStrategyMap(),
            $this->outputStrategyFactory->getStrategyMap()
        );
        foreach ($maps as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
                    continue;
                }
                if (in_array($stagingProvider, $cleanedProviders, true)) {
                    continue;
                }
                // don't clean ABS workspaces or Redshift workspaces which are reusable if created for a config
                if ($this->jobDefinition->getConfigId() && $this->isReusableWorkspace()) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
                    $cleanedProviders[] = $stagingProvider;
                } catch (ClientException $e) {
                    // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
                    $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage());
                }
            }
        }
    }

    private function isReusableWorkspace(): bool
    {
        return $this->component->getInputStagingStorage() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->component->getOutputStagingStorage() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->component->getInputStagingStorage() === AbstractStrategyFactory::WORKSPACE_REDSHIFT ||
            $this->component->getOutputStagingStorage() === AbstractStrategyFactory::WORKSPACE_REDSHIFT;
    }
}
