<?php
namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class JobDefinition
{
    /**
     * @var null|string
     */
    private $configId;

    /**
     * @var null|string
     */
    private $rowId;

    /**
     * @var null|string
     */
    private $configVersion;

    /**
     * @var Component
     */
    private $component;

    /**
     * @var array
     */
    private $configuration = [];

    /**
     * @var array
     */
    private $state = [];

    /**
     * @var bool
     */
    private $isDisabled = false;

    /**
     * JobDefinition constructor.
     *
     * @param array $configuration
     * @param Component $component
     * @param null|string $configId
     * @param null|string $configVersion
     * @param array $state
     * @param null|string $rowId
     * @param bool $isDisabled
     */
    public function __construct(array $configuration, Component $component, $configId = null, $configVersion = null, array $state = [], $rowId = null, $isDisabled = false)
    {
        $this->configuration = $this->normalizeConfiguration($configuration);
        $this->component = $component;
        $this->configId = $configId;
        $this->configVersion = $configVersion;
        $this->rowId = $rowId;
        $this->isDisabled = $isDisabled;
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getComponentId()
    {
        return $this->component->getId();
    }

    /**
     * @return null|string
     */
    public function getConfigId()
    {
        return $this->configId;
    }

    /**
     * @return null|string
     */
    public function getRowId()
    {
        return $this->rowId;
    }

    /**
     * @return null|string
     */
    public function getConfigVersion()
    {
        return $this->configVersion;
    }

    /**
     * @return Component
     */
    public function getComponent()
    {
        return $this->component;
    }

    /**
     * @return array
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * @return array
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @return bool
     */
    public function isDisabled()
    {
        return $this->isDisabled;
    }

    private function normalizeConfiguration($configuration)
    {
        try {
            $configuration = (new Configuration\Container())->parse(['container' => $configuration]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        $configuration['storage'] = empty($configuration['storage']) ? [] : $configuration['storage'];
        $configuration['processors'] = empty($configuration['processors']) ? [] : $configuration['processors'];
        $configuration['parameters'] = empty($configuration['parameters']) ? [] : $configuration['parameters'];

        return $configuration;
    }
}
