<?php
namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Filesystem\Temp;
use Syrup\ComponentBundle\Job\Executor as BaseExecutor;
use Syrup\ComponentBundle\Job\Metadata\Job;

class Executor extends BaseExecutor
{

    /**
     * @var Logger
     */
    protected $log;

    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @param Logger $log
     * @param Temp $temp
     */
    public function __construct(Logger $log, Temp $temp)
    {
        $this->log = $log;
        $this->temp = $temp;
    }

    /**
     * @param Job $job
     * @return array
     * @throws \Exception
     */
    public function execute(Job $job)
    {
        $params = $job->getParams();

        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $params["component"]) {
                $component = $c;
            }
        }

        if (!isset($component)) {
            throw new UserException("Component '{$params["component"]}' not found.");
        }

        // Get the formatters and change the component
        foreach ($this->log->getHandlers() as $handler) {
            if (get_class($handler->getFormatter()) == 'Keboola\\DockerBundle\\Monolog\\Formatter\\DockerBundleJsonFormatter') {
                $handler->getFormatter()->setAppName($params["component"]);
            }
        }

        // Manual config from request
        if (isset($params["configData"])) {
            $configData = $params["configData"];
        } else {
            // Read config from storage
            try {
                $components = new Components($this->storageApi);
                $configData = $components->getConfiguration($component["id"], $params["config"])["configuration"];
            } catch (ClientException $e) {
                throw new UserException("Error reading configuration '{$params["config"]}': " . $e->getMessage(), $e);
            }
        }

        try {
            $executor = new \Keboola\DockerBundle\Docker\Executor($this->storageApi, $this->log);
            $image = Image::factory($component["data"]);
            $container = new Container($image);
            $this->log->info("Running Docker container for '{$component['id']}'", $configData);
            $executor->setTmpFolder($this->temp->getTmpFolder());
            $process = $executor->run($container, $configData);
            $this->log->info("Docker container for '{$component['id']}' finished.");
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Parsing configuration failed: " . $e->getMessage(), $e);
        }


        if ($process->getOutput()) {
            $message = $process->getOutput();
        } else {
            $message = "Container finished.";
        }
        return ["message" => $message];
    }
}
