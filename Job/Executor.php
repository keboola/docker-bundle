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

        if (!empty($params['prepare'])) {
            if (!isset($params["configData"]) || empty($params["configData"])) {
                throw new UserException("Configuration must be specified in 'configData'.");
            }
            $configData = $params["configData"];

            // Add 50 rows limit for each table
            if (isset($configData['storage']['input']['tables']) &&
                is_array($configData['storage']['input']['tables'])
            ) {
                foreach ($configData['storage']['input']['tables'] as $index => $table) {
                    $table['limit'] = 50;
                    $configData['storage']['input']['tables'][$index] = $table;
                }
            }
        } else {
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
            $this->log->pushProcessor([$processor, 'processRecord']);

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
        }

        $executor = new \Keboola\DockerBundle\Docker\Executor($this->storageApi, $this->log);

        if (!empty($params['prepare'])) {
            $this->log->info("Preparing configuration.", $configData);

            // Dummy image and container
            $image = Image::factory([]);
            $image->setConfigFormat($params["format"]);

            $container = new Container($image, $this->log);

            $executor->setTmpFolder($this->temp->getTmpFolder());
            $executor->initialize($container, $configData);
            $executor->prepare($container);

            $message = 'Configuration prepared.';
            $this->log->info($message);
            return ["message" => $message];

        } else {
            $image = Image::factory($component["data"]);
            $container = new Container($image, $this->log);

            $this->log->info("Running Docker container for '{$component['id']}'.", $configData);

            $executor->setTmpFolder($this->temp->getTmpFolder());
            $executor->initialize($container, $configData);

            $process = $executor->run($container, $configData);
            if ($process->getOutput()) {
                $message = $process->getOutput();
            } else {
                $message = "Container finished successfully.";
            }
            $this->log->info("Docker container for '{$component['id']}' finished.");
            return ["message" => $message];
        }

    }
}
