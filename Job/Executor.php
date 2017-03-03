<?php
namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Service\Runner;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;
use Monolog\Logger;
use Symfony\Component\Process\Process;

class Executor extends BaseExecutor
{
    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @var ObjectEncryptor
     */
    protected $encryptor;

    /**
     * @var Components
     */
    protected $components;

    /**
     * @var ComponentWrapper
     */
    protected $encryptionComponent;

    /**
     * @var ComponentProjectWrapper
     */
    protected $encryptionComponentProject;

    /**
     * @var array Cached token information
     */
    private $tokenInfo;

    /**
     * @var LoggersService
     */
    private $logger;

    /**
     * @var Runner
     */
    private $runner;

    /**
     * @param Logger $logger
     * @param Runner $runner
     * @param ObjectEncryptor $encryptor
     * @param ComponentsService $components
     * @param ComponentWrapper $componentWrapper
     * @param ComponentProjectWrapper $componentProjectWrapper
     */
    public function __construct(
        Logger $logger,
        Runner $runner,
        ObjectEncryptor $encryptor,
        ComponentsService $components,
        ComponentWrapper $componentWrapper,
        ComponentProjectWrapper $componentProjectWrapper
    ) {
        $this->encryptor = $encryptor;
        $this->components = $components->getComponents();
        $this->encryptionComponent = $componentWrapper;
        $this->encryptionComponentProject = $componentProjectWrapper;
        $this->logger = $logger;
        $this->runner = $runner;
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
        $this->tokenInfo = $this->storageApi->verifyToken();
        $this->runner->setFeatures($this->tokenInfo["owner"]["features"]);
        $this->encryptionComponentProject->setProjectId($this->tokenInfo["owner"]["id"]);
        if (isset($job->getRawParams()["component"])) {
            $this->encryptionComponent->setComponentId($job->getRawParams()["component"]);
            $this->encryptionComponentProject->setComponentId($job->getRawParams()["component"]);
        }
        $params = $job->getParams();
        $containerId = null;
        $state = [];
        $configId = null;

        if ($params['mode'] == 'sandbox') {
            if (empty($params["configData"]) || !is_array($params["configData"])) {
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
            $component = ['data' => ['definition' => ['type' => 'dockerhub', 'uri' => 'sandbox-dummy-wont-download']]];
        } else {
            $component = $this->getComponent($params["component"]);

            if (!$this->storageApi->getRunId()) {
                $this->storageApi->setRunId($this->storageApi->generateRunId());
            }

            // Manual config from request
            if (isset($params["configData"]) && is_array($params["configData"])) {
                $configData = $params["configData"];
            } else {
                // Read config from storage
                try {
                    $configuration = $this->components->getConfiguration($component["id"], $params["config"]);
                    if (in_array("encrypt", $component["flags"])) {
                        $configData = $this->encryptor->decrypt($configuration["configuration"]);
                    } else {
                        $configData = $configuration["configuration"];
                    }
                    $state = $configuration["state"];
                } catch (ClientException $e) {
                    throw new UserException(
                        "Error reading configuration '{$params["config"]}': " . $e->getMessage(),
                        $e
                    );
                }
            }
        }
        if ($params && isset($params["config"])) {
            $configId = $params['config'];
        } else {
            $configId = sha1(serialize($params['configData']));
        }
        $this->runner->run($component, $configId, $configData, $state, 'run', $params['mode'], $job->getId());
        return ["message" => "Docker container processing finished."];
    }


    public function cleanup(Job $job)
    {
        $this->logger->info("Terminating job {$job->getId()}");
        try {
            $listCommand = 'sudo docker ps -aq --filter=label=com.keboola.docker-runner.jobId=' . $job->getId();
            while (intval(trim((new Process($listCommand . ' | wc -l'))->mustRun()->getOutput())) > 0) {
                // executing docker kill does not seem to be required, docker rm --force does it all
                (new Process($listCommand . ' | xargs --no-run-if-empty sudo docker rm --force'))->mustRun();
            }
            $this->logger->info("Job {$job->getId()} terminated");
        } catch (\Exception $e) {
            throw new ApplicationException("Job {$job->getId()} termination failed: " . $e->getMessage(), $e);
        }
    }
}
