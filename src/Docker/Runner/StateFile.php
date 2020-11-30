<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\ComponentState\Adapter;
use Keboola\DockerBundle\Docker\Configuration\State;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;

class StateFile
{
    const NAMESPACE_COMPONENT = 'component';

    const NAMESPACE_STORAGE = 'storage';

    const NAMESPACE_INPUT = 'input';

    const NAMESPACE_TABLES = 'tables';

    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var string
     */
    private $configurationId;

    /**
     * @var string
     */
    private $configurationRowId;

    /**
     * @var array
     */
    private $state;

    /**
     * @var string
     */
    private $format;

    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * @var mixed
     */
    private $currentState = null;

    public function __construct(
        $dataDirectory,
        ClientWrapper $clientWrapper,
        ObjectEncryptorFactory $encryptorFactory,
        array $state,
        $format,
        $componentId,
        $configurationId,
        OutputFilterInterface $outputFilter,
        LoggerInterface $logger,
        $configurationRowId = null
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->clientWrapper = $clientWrapper;
        $this->encryptorFactory = $encryptorFactory;
        $this->componentId = $componentId;
        $this->configurationId = $configurationId;
        $this->configurationRowId = $configurationRowId;
        try {
            $parsedState = (new State())->parse(['state' => $state]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Invalid state: " . $e->getMessage(), $e, $state);
        }
        if (isset($parsedState[self::NAMESPACE_COMPONENT])) {
            $this->state = $parsedState[self::NAMESPACE_COMPONENT];
        } else {
            $this->state = [];
        }
        $this->format = $format;
        $this->outputFilter = $outputFilter;
        $this->outputFilter->collectValues($state);
        $this->logger = $logger;
    }

    public function createStateFile()
    {
        // Store state
        $stateAdapter = new Adapter($this->format);
        $stateAdapter->setConfig($this->state);
        $stateFileName = $this->dataDirectory. DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR .
            'state' . $stateAdapter->getFileExtension();
        $stateAdapter->writeToFile($stateFileName);
    }

    public function stashState($currentState)
    {
        $this->currentState = $currentState;
    }

    public function persistState(InputTableStateList $inputTableStateList)
    {
        $this->outputFilter->collectValues((array)$this->currentState);

        if ($this->clientWrapper->hasBranch()) {
            $client = $this->clientWrapper->getBranchClient();
        } else {
            $client = $this->clientWrapper->getBasicClient();
        }

        $configuration = new Configuration();
        $configuration->setComponentId($this->componentId);
        $configuration->setConfigurationId($this->configurationId);
        try {
            if ($this->currentState !== null) {
                $encryptedStateData = $this->encryptorFactory->getEncryptor()->encrypt(
                    $this->currentState,
                    $this->encryptorFactory->getEncryptor()->getRegisteredProjectWrapperClass()
                );
            } else {
                $encryptedStateData = [];
            }
            $state = [
                self::NAMESPACE_COMPONENT => $encryptedStateData,
                self::NAMESPACE_STORAGE => [
                    self::NAMESPACE_INPUT => [
                        self::NAMESPACE_TABLES => $inputTableStateList->jsonSerialize()
                    ]
                ]
            ];
            $this->logger->notice("Storing state: " . json_encode($state));
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
                throw new UserException("Failed to store state: " . $e->getMessage(), $e);
            }
            throw $e;
        }
    }

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
        return $client->apiPut(
            sprintf(
                'components/%s/configs/%s/state',
                $configuration->getComponentId(),
                $configuration->getConfigurationId()
            ),
            [
                'state' => json_encode($configuration->getState())
            ]
        );
    }

    private function saveConfigurationRowState(ConfigurationRow $row, Client $client)
    {
        return $client->apiPut(
            sprintf(
                "components/%s/configs/%s/rows/%s/state",
                $row->getComponentConfiguration()->getComponentId(),
                $row->getComponentConfiguration()->getConfigurationId(),
                $row->getRowId()
            ),
            [
                'state' => json_encode($row->getState())
            ]
        );
    }
}
