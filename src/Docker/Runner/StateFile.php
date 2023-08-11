<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\ComponentState\Adapter;
use Keboola\DockerBundle\Docker\Configuration\State;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ConfigurationRowState;
use Keboola\StorageApi\Options\Components\ConfigurationState;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;

class StateFile
{
    public const NAMESPACE_COMPONENT = 'component';
    public const NAMESPACE_STORAGE = 'storage';
    public const NAMESPACE_INPUT = 'input';
    public const NAMESPACE_TABLES = 'tables';
    public const NAMESPACE_FILES = 'files';

    private string $dataDirectory;
    private ClientWrapper $clientWrapper;
    private JobScopedEncryptor $encryptor;
    private string $format;
    private string $componentId;
    private ?string $configurationId;
    private ?string $configurationRowId;
    private OutputFilterInterface $outputFilter;
    private LoggerInterface $logger;

    /**
     * @var array
     */
    private $state;

    /**
     * @var array|stdClass
     */
    private $currentState;

    public function __construct(
        string $dataDirectory,
        ClientWrapper $clientWrapper,
        JobScopedEncryptor $encryptor,
        array $state,
        string $format,
        string $componentId,
        ?string $configurationId,
        OutputFilterInterface $outputFilter,
        LoggerInterface $logger,
        ?string $configurationRowId = null
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->clientWrapper = $clientWrapper;
        $this->encryptor = $encryptor;
        $this->format = $format;
        $this->componentId = $componentId;
        $this->logger = $logger;
        $this->configurationId = $configurationId;
        $this->configurationRowId = $configurationRowId;

        try {
            $parsedState = (new State())->parse(['state' => $state]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException('Invalid state: ' . $e->getMessage(), $e, $state);
        }
        if (isset($parsedState[self::NAMESPACE_COMPONENT])) {
            $this->state = $parsedState[self::NAMESPACE_COMPONENT];
        } else {
            $this->state = [];
        }

        $this->outputFilter = $outputFilter;
        $this->outputFilter->collectValues($state);
    }

    public function createStateFile(): void
    {
        // Store state
        $stateAdapter = new Adapter($this->format);
        $stateAdapter->setConfig($this->state);
        $stateFileName = $this->dataDirectory. DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR .
            'state' . $stateAdapter->getFileExtension();
        $stateAdapter->writeToFile($stateFileName);
    }

    /**
     * @param array|stdClass $currentState
     */
    public function stashState($currentState): void
    {
        $this->currentState = $currentState;
    }

    public function persistState(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList
    ): void {
        $this->outputFilter->collectValues((array) $this->currentState);

        if ($this->clientWrapper->isDevelopmentBranch()) {
            $client = $this->clientWrapper->getBranchClient();
        } else {
            $client = $this->clientWrapper->getBasicClient();
        }

        $configuration = new Configuration();
        $configuration->setComponentId($this->componentId);
        $configuration->setConfigurationId($this->configurationId);
        try {
            if ($this->currentState !== null) {
                $encryptedStateData = $this->encryptor->encrypt($this->currentState);
            } else {
                $encryptedStateData = [];
            }
            $state = [
                self::NAMESPACE_COMPONENT => $encryptedStateData,
                self::NAMESPACE_STORAGE => [
                    self::NAMESPACE_INPUT => [
                        self::NAMESPACE_TABLES => $inputTableStateList->jsonSerialize(),
                        self::NAMESPACE_FILES => $inputFileStateList->jsonSerialize(),
                    ],
                ],
            ];
            $this->logger->notice('Storing state: ' . json_encode($state));
            if ($this->configurationRowId) {
                $configurationRow = new ConfigurationRow($configuration);
                $configurationRow->setRowId($this->configurationRowId);
                $configurationRow->setState($state);
                $this->saveConfigurationRowState($configurationRow, $client);
            } else {
                $configuration->setState($state);
                $this->saveConfigurationState($configuration, $client);
            }
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                throw new UserException('Failed to store state: ' . $e->getMessage(), $e);
            }
            throw $e;
        }
    }

    /**
     * @return array|object
     */
    public function loadStateFromFile()
    {
        $stateAdapter = new Adapter($this->format);
        $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state'
            . $stateAdapter->getFileExtension();
        $fs = new Filesystem();
        if ($fs->exists($fileName)) {
            $state = $stateAdapter->readFromFile($fileName);
        } else {
            $state = [];
        }
        $fs->remove($fileName);
        return $state;
    }

    private function saveConfigurationState(Configuration $configuration, Client $client)
    {
        $componentsApi = new Components($client);
        $configurationState = new ConfigurationState();
        $configurationState->setConfigurationId($configuration->getConfigurationId());
        $configurationState->setComponentId($configuration->getComponentId());
        $configurationState->setState($configuration->getState());
        return $componentsApi->updateConfigurationState(
            $configurationState
        );
    }

    private function saveConfigurationRowState(ConfigurationRow $row, Client $client)
    {
        $componentsApi = new Components($client);
        $configurationRowState = new ConfigurationRowState($row->getComponentConfiguration());
        $configurationRowState->setRowId($row->getRowId());
        $configurationRowState->setState($row->getState());
        return $componentsApi->updateConfigurationRowState(
            $configurationRowState
        );
    }
}
