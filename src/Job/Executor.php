<?php

namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile;
use Keboola\DockerBundle\Service\Runner;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
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
     * @var ObjectEncryptorFactory
     */
    protected $encryptorFactory;

    /**
     * @var Components
     */
    protected $components;

    /**
     * @var array Cached token information
     */
    private $tokenInfo;

    /**
     * @var LoggersService
     */
    private $logger;

    /**
     * @var JobMapper
     */
    private $jobMapper;

    /**
     * @var LoggersService
     */
    private $loggerService;

    /**
     * @var string
     */
    private $oauthApiUrl;

    /**
     * @var array
     */
    private $instanceLimits;

    /**
     * @param LoggersService $loggersService
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param ComponentsService $components
     * @param $storageApiUrl
     * @param JobMapper $jobMapper
     * @param string $oauthApiUrl
     * @param array $instanceLimits
     * @throws \Keboola\ObjectEncryptor\Exception\ApplicationException
     */
    public function __construct(
        LoggersService $loggersService,
        ObjectEncryptorFactory $encryptorFactory,
        ComponentsService $components,
        $storageApiUrl,
        JobMapper $jobMapper,
        $oauthApiUrl,
        array $instanceLimits
    ) {
        $this->encryptorFactory = $encryptorFactory;
        $this->components = $components->getComponents();
        $this->logger = $loggersService->getLog();
        $this->loggerService = $loggersService;
        $this->jobMapper = $jobMapper;
        $this->encryptorFactory->setStackId(parse_url($storageApiUrl, PHP_URL_HOST));
        $this->oauthApiUrl = $oauthApiUrl;
        $this->instanceLimits = $instanceLimits;
    }

    /**
     * @param $id
     * @return array
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
            throw new \Keboola\Syrup\Exception\UserException("Component '{$id}' not found.");
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
        try {
            $this->tokenInfo = $this->storageApi->verifyToken();
            $this->encryptorFactory->setProjectId($this->tokenInfo["owner"]["id"]);
            if (isset($job->getRawParams()["component"])) {
                $this->encryptorFactory->setComponentId($job->getRawParams()["component"]);
            }
            $job->setEncryptor($this->encryptorFactory->getEncryptor());
            $params = $job->getParams();
            if (isset($params['row']) && is_scalar($params['row'])) {
                $rowId = ($params['row']);
            } else {
                if (isset($params['row'])) {
                    throw new \Keboola\Syrup\Exception\UserException("Unsupported row value (" . var_export($params['row'], true) . "), scalar is required.");
                }
                $rowId = null;
            }

            $jobDefinitionParser = new JobDefinitionParser();

            $component = $this->getComponent($params["component"]);
            if (!empty($params['tag'])) {
                $this->logger->warn("Overriding component tag with: '" . $params['tag'] . "'");
                $component['data']['definition']['tag'] = $params['tag'];
            }

            if (!$this->storageApi->getRunId()) {
                $this->storageApi->setRunId($this->storageApi->generateRunId());
            }

            // Manual config from request
            if (isset($params["configData"]) && is_array($params["configData"])) {
                $configId = null;
                if (isset($params["config"])) {
                    $configId = $params["config"];
                }
                $jobDefinitionParser->parseConfigData(new Component($component), $params["configData"], $configId);
            } else {
                // Read config from storage
                try {
                    $configuration = $this->components->getConfiguration($component["id"], $params["config"]);
                    $jobDefinitionParser->parseConfig(new Component($component), $this->encryptorFactory->getEncryptor()->decrypt($configuration));
                } catch (ClientException $e) {
                    throw new \Keboola\Syrup\Exception\UserException(
                        "Error reading configuration '{$params["config"]}': " . $e->getMessage(),
                        $e
                    );
                }
            }

            $jobDefinitions = $jobDefinitionParser->getJobDefinitions();
            $usageFile = new UsageFile();
            $usageFile->setJobMapper($this->jobMapper);
            $runner = new Runner(
                $this->encryptorFactory,
                $this->storageApi,
                $this->loggerService,
                $this->oauthApiUrl,
                $this->instanceLimits
            );
            $outputs = $runner->run(
                $jobDefinitions,
                'run',
                $params['mode'],
                $job->getId(),
                $usageFile,
                $rowId
            );
            if (count($outputs) === 0) {
                return [
                    "message" => "No configurations executed.",
                    "images" => [],
                    "configVersion" => null,
                ];
            }
            return [
                "message" => "Component processing finished.",
                "images" => array_map(function (Output $output) {
                    return $output->getImages();
                }, $outputs),
                "configVersion" => $outputs[0]->getConfigVersion(),
            ];
        } catch (\Keboola\DockerBundle\Exception\UserException $e) {
            throw new \Keboola\Syrup\Exception\UserException($e->getMessage(), $e);
        } catch (\Keboola\DockerBundle\Exception\InitializationException $e) {
            throw new \Keboola\Syrup\Job\Exception\InitializationException($e->getMessage(), $e);
        }
    }

    /**
     * @param Job $job
     */
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
            throw new \Keboola\Syrup\Exception\ApplicationException("Job {$job->getId()} termination failed: " . $e->getMessage(), $e);
        }
    }
}
