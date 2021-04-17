<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\Gelf\ServerFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

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
     * @var array
     */
    private $features;

    /**
     * Component constructor.
     * @param array $componentData Component data as returned by Storage API
     */
    public function __construct(array $componentData)
    {
        $this->id = empty($componentData['id']) ? '' : $componentData['id'];
        $data = empty($componentData['data']) ? [] : $componentData['data'];
        if (isset($componentData['features'])) {
            $this->features = $componentData['features'];
        } else {
            $this->features = [];
        }
        try {
            $this->data = (new Configuration\Component())->parse(['config' => $data]);
        } catch (InvalidConfigurationException $e) {
            throw new ApplicationException(
                'Component definition is invalid. Verify the deployment setup and the repository settings ' .
                'in the Developer Portal. Detail: ' . $e->getMessage(),
                $e,
                $data
            );
        }
        $this->networkType = $this->data['network'];
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
    public function getSanitizedComponentId()
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
     * @return bool
     */
    public function runAsRoot()
    {
        return in_array('container-root-user', $this->features);
    }

    /**
     * @return bool
     */
    public function blockBranchJobs()
    {
        return in_array('dev-branch-job-blocked', $this->features);
    }

    /**
     * @return bool
     */
    public function branchConfigurationsAreUnsafe()
    {
        return in_array('dev-branch-configuration-unsafe', $this->features);
    }

    /**
     * @return bool
     */
    public function allowBranchMapping()
    {
        return in_array('dev-mapping-allowed', $this->features);
    }

    /**
     * @return bool
     */
    public function hasNoSwap()
    {
        return in_array('no-swap', $this->features);
    }

    /**
     * @return bool
     */
    public function allowUseFileStorageOnly()
    {
        return in_array('allow-use-file-storage-only', $this->features);
    }

    /**
     * Change type of component image
     * @param $type
     * @return Component
     */
    public function changeType($type)
    {
        $data = ['data' => $this->data, 'id' => $this->id];
        $data['data']['network'] = $this->networkType;
        $data['data']['definition']['type'] = $type;
        return new Component($data);
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

    public function setImageTag($tag)
    {
        if (!is_string($tag)) {
            throw new \InvalidArgumentException(sprintf(
                'Argument $tag is expected to be a string, %s given',
                gettype($tag)
            ));
        }

        $this->data['definition']['tag'] = $tag;
    }

    public function getImageTag()
    {
        return $this->data['definition']['tag'];
    }

    public function setNetworkType($value)
    {
        if (!in_array($value, ['none', 'bridge', 'no-internet'])) {
            throw new ApplicationException("Network mode $value is not supported.");
        } else {
            $this->networkType = $value;
        }
    }

    public function getStagingStorage()
    {
        return $this->data['staging_storage'];
    }

    public function isApplicationErrorDisabled()
    {
        return $this->data['logging']['no_application_errors'];
    }
}
