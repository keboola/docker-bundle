<?php
namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Logger;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;
use Symfony\Component\Process\Process;

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
     * @param $id
     */
    protected function getComponent($id)
    {
        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $id) {
                $component = $c;
            }
        }
        if (!isset($component)) {
            throw new UserException("Component '{$id}' not found.");
        }
        return $component;
    }

    /**
     * @param Job $job
     * @return array
     * @throws \Exception
     */
    public function execute(Job $job)
    {
        $params = $job->getParams();
        $this->temp->setId($job->getId());

        if ($params['mode'] == 'sandbox') {
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
            $component = null;
        } else {
            $component = $this->getComponent($params["component"]);
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
        $containerId = $params["component"] . "-" . $this->storageApi->getRunId();

        switch ($params['mode']) {
            case 'sandbox':
                $this->log->info("Preparing configuration.", $configData);

                // Dummy image and container
                $image = Image::factory([]);
                $image->setConfigFormat($params["format"]);

                $container = new Container($image, $this->log);

                $executor->setTmpFolder($this->temp->getTmpFolder());
                $executor->initialize($container, $configData);
                $executor->storeDataArchive($container, ['sandbox', 'docker']);

                $message = 'Configuration prepared.';
                $this->log->info($message);
                return ["message" => $message];
            case 'input':
                $this->log->info("Preparing image configuration.", $configData);

                $image = Image::factory($component["data"]);
                $container = new Container($image, $this->log);

                $executor->setTmpFolder($this->temp->getTmpFolder());
                $executor->initialize($container, $configData);
                $executor->storeDataArchive($container, ['input', 'docker']);

                $message = 'Image configuration prepared.';
                $this->log->info($message);
                return ["message" => $message];
            case 'dry-run':
                $image = Image::factory($component["data"]);
                $container = new Container($image, $this->log);
                $this->log->info("Running Docker container for '{$component['id']}'.", $configData);

                $executor->setTmpFolder($this->temp->getTmpFolder());
                $executor->initialize($container, $configData);
                $process = $executor->run($container, $containerId);
                $executor->storeDataArchive($container, ['dry-run', 'docker']);

                if ($process->getOutput()) {
                    $message = $process->getOutput();
                } else {
                    $message = "Container finished successfully.";
                }

                $this->log->info("Docker container for '{$component['id']}' finished.");
                return ["message" => $message];
            case 'run':
                $image = Image::factory($component["data"]);
                $container = new Container($image, $this->log);
                $this->log->info("Running Docker container for '{$component['id']}'.", $configData);

                $executor->setTmpFolder($this->temp->getTmpFolder());
                $executor->initialize($container, $configData);
                $process = $executor->run($container, $containerId);
                $executor->storeOutput($container, $configData);

                if ($process->getOutput()) {
                    $message = $process->getOutput();
                } else {
                    $message = "Container finished successfully.";
                }

                $this->log->info("Docker container for '{$component['id']}' finished.");
                return ["message" => $message];
            default:
                throw new ApplicationException("Invalid run mode " . $params['mode']);
        }
    }

    /**
     *
     */
    public function cleanup()
    {
        $params = $this->job->getParams();
        if (isset($params["component"])) {
            $containerId = $params["component"] . "-" . $this->storageApi->getRunId();
            $this->log->info("Terminating process");
            try {
                $process = new Process('sudo docker ps | grep ' . escapeshellarg($containerId) .' | wc -l');
                $process->run();
                if (trim($process->getOutput()) !== '0') {
                    (new Process('sudo docker kill ' . escapeshellarg($containerId)))->run();
                }
                $this->log->info("Process terminated");
            } catch (\Exception $e) {
                $this->log->error("Cannot terminate container '{$containerId}': " . $e->getMessage());
            }

        }

    }
}
