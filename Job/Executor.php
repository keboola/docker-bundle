<?php
namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Executor as DockerExecutor;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;
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
    private $logService;

    /**
     * @param Temp $temp
     * @param ObjectEncryptor $encryptor
     * @param ComponentsService $components
     * @param ComponentWrapper $componentWrapper
     * @param ComponentProjectWrapper $componentProjectWrapper
     * @param LoggersService $logService
     */
    public function __construct(
        Temp $temp,
        ObjectEncryptor $encryptor,
        ComponentsService $components,
        ComponentWrapper $componentWrapper,
        ComponentProjectWrapper $componentProjectWrapper,
        LoggersService $logService
    ) {
        $this->temp = $temp;
        $this->encryptor = $encryptor;
        $this->components = $components->getComponents();
        $this->encryptionComponent = $componentWrapper;
        $this->encryptionComponentProject = $componentProjectWrapper;
        $this->logService = $logService;
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

        $this->encryptionComponentProject->setProjectId($this->tokenInfo["owner"]["id"]);
        if (isset($job->getRawParams()["component"])) {
            $this->encryptionComponent->setComponentId($job->getRawParams()["component"]);
            $this->encryptionComponentProject->setComponentId($job->getRawParams()["component"]);
        }
        $params = $job->getParams();
        $this->temp->setId($job->getId());
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
            $component = [];
        } else {
            $component = $this->getComponent($params["component"]);
            $this->logService->setComponentId($component["id"]);
            if (!$this->storageApi->getRunId()) {
                $this->storageApi->generateRunId();
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
        return $this->doExecute($component, $params, $configData, $state);
    }

    /**
     * @param $component
     * @param $params
     * @param $configData
     * @param $state
     * @return array
     * @throws \Exception
     */
    private function doExecute(array $component, array $params, array $configData, array $state)
    {
        $oauthCredentialsClient = new Credentials($this->storageApi->getTokenString());
        $oauthCredentialsClient->enableReturnArrays(true);
        $executor = new DockerExecutor(
            $this->storageApi,
            $this->logService->getLog(),
            $oauthCredentialsClient,
            $this->temp->getTmpFolder()
        );
        if ($component && isset($component["id"])) {
            $executor->setComponentId($component["id"]);
        }
        if ($params && isset($params["config"])) {
            $executor->setConfigurationId($params["config"]);
            $configId = $params['config'];
        } else {
            $configId = sha1(serialize($params['configData']));
        }

        switch ($params['mode']) {
            case 'sandbox':
                $this->logService->getLog()->info("Preparing configuration.", $configData);

                // Dummy image and container
                $dummyConfig = array(
                    "definition" => array(
                        "type" => "dummy",
                        "uri" => "dummy"
                    )
                );
                $image = Image::factory($this->encryptor, $this->logService->getLog(), $dummyConfig);
                $image->setConfigFormat($params["format"]);
                $this->logService->setVerbosity($image->getLoggerVerbosity());
                $container = new Container($image, $this->logService->getLog(), $this->logService->getContainerLog());
                $executor->initialize($container, $configData, $state, true);
                $executor->storeDataArchive($container, ['sandbox', 'docker']);
                $message = 'Configuration prepared.';
                $this->logService->getLog()->info($message);
                break;
            case 'input':
                $this->logService->getLog()->info("Preparing image configuration.", $configData);

                $image = Image::factory($this->encryptor, $this->logService->getLog(), $component["data"]);
                $this->logService->setVerbosity($image->getLoggerVerbosity());
                $container = new Container($image, $this->logService->getLog(), $this->logService->getContainerLog());
                $executor->initialize($container, $configData, $state, true);
                $executor->storeDataArchive($container, ['input', 'docker', $component['id']]);
                $message = 'Image configuration prepared.';
                $this->logService->getLog()->info($message);
                break;
            case 'dry-run':
                $this->logService->getLog()->info("Running Docker container '{$component['id']}'.", $configData);

                $containerId = $component["id"] . "-" . $this->storageApi->getRunId();
                $image = Image::factory($this->encryptor, $this->logService->getLog(), $component["data"]);
                $this->logService->setVerbosity($image->getLoggerVerbosity());
                $container = new Container($image, $this->logService->getLog(), $this->logService->getContainerLog());
                $executor->initialize($container, $configData, $state, true);
                $message = $executor->run($container, $containerId, $this->tokenInfo, $configId);
                $executor->storeDataArchive($container, ['dry-run', 'docker', $component['id']]);

                $this->logService->getLog()->info("Docker container '{$component['id']}' finished.");
                break;
            case 'run':
                $this->logService->getLog()->info("Running Docker container '{$component['id']}'.", $configData);

                $containerId = $component["id"] . "-" . $this->storageApi->getRunId();
                $image = Image::factory($this->encryptor, $this->logService->getLog(), $component["data"]);
                $this->logService->setVerbosity($image->getLoggerVerbosity());
                $container = new Container($image, $this->logService->getLog(), $this->logService->getContainerLog());
                $executor->initialize($container, $configData, $state, false);
                $message = $executor->run($container, $containerId, $this->tokenInfo, $configId);
                $executor->storeOutput($container, $state);

                $this->logService->getLog()->info("Docker container '{$component['id']}' finished.");
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $params['mode']);
        }
        if (!$message) {
            $message = "Docker container processing finished.";
        }
        return array("message" => $message);
    }

    public function cleanup(Job $job)
    {
        $params = $job->getRawParams();
        if (isset($params["component"])) {
            $containerId = $params["component"] . "-" . $this->storageApi->getRunId();
            $this->logService->getLog()->info("Terminating process");
            try {
                $process = new Process('sudo docker ps | grep ' . escapeshellarg($containerId) .' | wc -l');
                $process->run();
                if (trim($process->getOutput()) !== '0') {
                    (new Process('sudo docker kill ' . escapeshellarg($containerId)))->run();
                }
                $this->logService->getLog()->info("Process terminated");
            } catch (\Exception $e) {
                $this->logService->getLog()->error("Cannot terminate container '{$containerId}': " . $e->getMessage());
            }
        }
    }
}
