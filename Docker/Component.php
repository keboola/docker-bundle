<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\Gelf\ServerFactory;
use Keboola\Syrup\Exception\ApplicationException;

class Component
{
    /**
     * @var string
     */
    private $id;

    /**
     * @var array
     */
    private $data;

    /**
     * @var string
     */
    private $networkType;

    /**
     * Component constructor.
     * @param array $componentData Component data as returned by Storage API
     */
    public function __construct(array $componentData)
    {
        $this->id = empty($component['id']) ? '' : $component['id'];
        $data = empty($component['data']) ? [] : $component['data'];
        $this->data = (new Configuration\Component())->parse(['config' => $data]);
        if (empty($this->data["definition"])) {
            throw new ApplicationException("Image definition is empty or invalid.", null, $this->data);
        }
        $this->networkType = $this->data['network_type'];
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    private function getSanitizedComponentId()
    {
        return preg_replace('/[^a-zA-Z0-9-]/i', '-', $this->getId());
    }

    /**
     * @return string
     */
    public function getConfigurationFormat()
    {
        return $this->data['configuration_format'];
    }

    /**
     * @return array
     */
    public function getImageParameters()
    {
        return $this->data['image_parameters'];
    }

    /**
     * @return bool
     */
    public function hasDefaultBucket()
    {
        return !empty($this->data['default_bucket']);
    }

    /**
     * @param $configId
     * @return string
     */
    public function getDefaultBucketName($configId)
    {
        return $this->data['default_bucket_stage'] . ".c-" . $this->getSanitizedComponentId() . "-" . $configId;
    }

    /**
     * @return bool
     */
    public function injectEnvironment()
    {
        return !empty($this->data['inject_environment']);
    }

    public function forwardToken()
    {
        return $this->data['forward_token'];
    }

    public function forwardTokenDetails()
    {
        return $this->data['forward_token_details'];
    }

    public function getType()
    {
        return $this->data['definition']['type'];
    }

    /**
     * @return string
     */
    public function getLoggerType()
    {
        if (!empty($this->data['logging'])) {
            return $this->data['logging']['type'];
        }
        return 'standard';
    }

    /**
     * @return array
     */
    public function getLoggerVerbosity()
    {
        if (!empty($this->data['logging'])) {
            return $this->data['logging']['verbosity'];
        }
        return [];
    }

    /**
     * @return string
     */
    public function getLoggerServerType()
    {
        if (!empty($this->data['logging'])) {
            switch ($this->data['logging']['gelf_server_type']) {
                case 'udp':
                    return ServerFactory::SERVER_UDP;
                    break;
                case 'tcp':
                    return ServerFactory::SERVER_TCP;
                    break;
                case 'http':
                    return ServerFactory::SERVER_HTTP;
                    break;
                default:
                    throw new ApplicationException(
                        "Server type '{$this->data['logging']['gelf_server_type']}' not supported"
                    );
            }
        }
        return ServerFactory::SERVER_TCP;
    }

    /**
     * @return string
     */
    public function getNetworkType()
    {
        return $this->networkType;
    }

    /**
     * @return int
     */
    public function getCpuShares()
    {
        return $this->data['cpu_shares'];
    }

    /**
     * @return string
     */
    public function getMemory()
    {
        return $this->data['memory'];
    }

    /**
     * @return int
     */
    public function getProcessTimeout()
    {
        return (int)($this->data['process_timeout']);
    }

    public function getImageDefinition()
    {
        return $this->data['definition'];
    }

    public function setNetworkType($value)
    {
        $this->networkType = $value;
    }
}
