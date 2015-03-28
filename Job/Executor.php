<?php
namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Monolog\Logger;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;

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

        $processor = new DockerProcessor($component['id']);
        // attach the processor to all handlers and channels
        foreach ($this->log->getHandlers() as $handler) {
            $handler->pushProcessor(array($processor, 'processRecord'));
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
        if (!empty($params['dryRun'])) {
            if (isset($configData['storage']['input']['tables']) &&
                is_array($configData['storage']['input']['tables'])
            ) {
                foreach ($configData['storage']['input']['tables'] as $index => $table) {
                    $table['limit'] = 50;
                    $configData['storage']['input']['tables'][$index] = $table;
                }
            }
        }

        $executor = new \Keboola\DockerBundle\Docker\Executor($this->storageApi, $this->log);
        $image = Image::factory($component["data"]);
        $container = new Container($image);
        $this->log->info("Running Docker container for '{$component['id']}'.", $configData);
        $executor->setTmpFolder($this->temp->getTmpFolder());
        $executor->initialize($container, $configData);
        if (!empty($params['dryRun'])) {
            $executor->dryRun($container);
            $message = 'Dry run finished, docker container did not run.';
            $this->log->info($message);
        } else {
            $process = $executor->run($container, $configData);
            if ($process->getOutput()) {
                $message = $process->getOutput();
            } else {
                $message = "Container finished successfully.";
            }
            $this->log->info("Docker container for '{$component['id']}' finished.");
        }

        return ["message" => $message];
    }
}
