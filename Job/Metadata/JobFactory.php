<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Job\Metadata;

use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;

class JobFactory extends \Keboola\Syrup\Job\Metadata\JobFactory
{

    /**
     *
     * Temporary storage used in `create` method to pass parameters to `getComponentConfiguration` method
     *
     * @var array
     */
    private $tmpParams = [];

    /**
     * @param $command
     * @param array $params
     * @param null $lockName
     * @return Job
     * @throws UserException
     * @throws \Exception
     */
    public function create($command, array $params = [], $lockName = null)
    {
        $this->tmpParams = $params;
        $job = parent::create($command, $params, $lockName);
        $params = $job->getRawParams();
        if (isset($params["mode"]) && $params["mode"] == "sandbox") {
            $job->setEncrypted(false);
        }
        return $job;
    }

    /**
     * @return mixed
     * @throws UserException
     */
    protected function getComponentConfiguration()
    {
        if (!isset($this->tmpParams["component"]) && $this->tmpParams["mode"] != "sandbox") {
            throw new ApplicationException('Component not set.');
        }

        if (!isset($this->tmpParams["component"]) && $this->tmpParams["mode"] == "sandbox") {
            $id = $this->componentName;
        } else {
            $id = $this->tmpParams["component"];
        }

        // Check list of components
        $components = $this->storageApiClient->indexAction();

        foreach ($components["components"] as $c) {
            if ($c["id"] == $id) {
                return $c;
            }
        }

        throw new UserException("Component '{$id}' not found.");
    }
}
