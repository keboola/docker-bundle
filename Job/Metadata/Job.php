<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Job\Metadata;

/**
 * Class Job, added functionality
 * @package Keboola\DockerBundle\Job\Metadata
 */
class Job extends \Keboola\Syrup\Job\Metadata\Job
{
    /**
     *
     * return componentId
     *
     * @return mixed
     */
    public function getComponentId()
    {
        if (isset($this->getProperty('params')["component"])) {
            return $this->getProperty('params')["component"];
        }
        return false;
    }

    /**
     *
     * Do not decrypt /sandbox job
     * TODO Do not decrypt jobs where component specifically not set with encryp flag
     *
     * @return string
     */
    public function getParams()
    {
        $params = $this->getProperty('params');
        if (isset($params["mode"]) && $params["mode"] == "sandbox") {
            return $params;
        }
        return $this->getEncryptor()->decrypt($this->getProperty('params'));
    }
}
