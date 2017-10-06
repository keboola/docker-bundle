<?php
namespace Keboola\DockerBundle\Docker;

use Keboola\Syrup\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class JobDefinition
{
    /**
     * @var
     */
    private $configId;

    /**
     * @var string
     */
    private $rowId;

    /**
     * @var string
     */
    private $configVersion;

    /**
     * @var string
     */
    private $rowVersion;

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

    public function __construct($configuration)
    {
        $this->configuration = $this->normalizeConfiguration($configuration);

        return $this;
    }

    /**
     * @return string
     */
    public function getComponentId()
    {
        return $this->component ? $this->component->getId() : null;
    }

    /**
     * @return mixed
     */
    public function getConfigId()
    {
        return $this->configId;
    }

    /**
     * @param mixed $configId
     * @return $this
     */
    public function setConfigId($configId)
    {
        $this->configId = $configId;

        return $this;
    }

    /**
     * @return string
     */
    public function getRowId()
    {
        return $this->rowId;
    }

    /**
     * @param string $rowId
     * @return $this
     */
    public function setRowId($rowId)
    {
        $this->rowId = $rowId;

        return $this;
    }

    /**
     * @return string
     */
    public function getConfigVersion()
    {
        return $this->configVersion;
    }

    /**
     * @param string $configVersion
     * @return $this
     */
    public function setConfigVersion($configVersion)
    {
        $this->configVersion = $configVersion;

        return $this;
    }

    /**
     * @return string
     */
    public function getRowVersion()
    {
        return $this->rowVersion;
    }

    /**
     * @param string $rowVersion
     * @return $this
     */
    public function setRowVersion($rowVersion)
    {
        $this->rowVersion = $rowVersion;

        return $this;
    }

    /**
     * @return Component
     */
    public function getComponent()
    {
        return $this->component;
    }

    /**
     * @param Component $component
     * @return $this
     */
    public function setComponent(Component $component)
    {
        $this->component = $component;

        return $this;
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
     * @param array $state
     * @return $this
     */
    public function setState(array $state)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDisabled()
    {
        return $this->isDisabled;
    }

    /**
     * @param bool $isDisabled
     * @return $this
     */
    public function setIsDisabled($isDisabled)
    {
        $this->isDisabled = $isDisabled;

        return $this;
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
