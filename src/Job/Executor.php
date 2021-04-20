<?php

namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\CreditsChecker;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFile;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\SharedCodeResolver;
use Keboola\DockerBundle\Docker\VariableResolver;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Syrup\Elasticsearch\JobMapper;
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
     * @var Runner
     */
    private $runner;

    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    /**
     * @param LoggersService $loggersService
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param ComponentsService $components
     * @param $storageApiUrl
     * @param StorageApiService $storageApiService
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
        StorageApiService $storageApiService,
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
        $this->clientWrapper = new ClientWrapper(
            $storageApiService->getClient(),
            $storageApiService->getStepPollDelayFunction(),
            $storageApiService->getLogger()
        );
        $this->storageApi = $this->clientWrapper->getBasicClient();
        $this->oauthApiUrl = $oauthApiUrl;
        $this->instanceLimits = $instanceLimits;
        $this->runner = new Runner(
            $this->encryptorFactory,
            $this->clientWrapper,
            $this->loggerService,
            $this->oauthApiUrl,
            $this->instanceLimits
        );
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
            if (!$this->storageApi->getRunId()) {
                $this->storageApi->setRunId($this->storageApi->generateRunId());
            }

            if (!empty($this->tokenInfo['admin']['role']) && ($this->tokenInfo['admin']['role'] === 'readOnly')) {
                throw new \Keboola\Syrup\Exception\UserException('As a readOnly user you cannot run a job.');
            }

            $creditsChecker = new CreditsChecker($this->storageApi);
            if (!$creditsChecker->hasCredits()) {
                throw new \Keboola\Syrup\Exception\UserException('You do not have credits to run a job');
            }

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
            if (isset($params['branchId'])) {
                $this->clientWrapper->setBranchId($params['branchId']);
            } else {
                $this->clientWrapper->setBranchId('');
            }

            $jobDefinitionParser = new JobDefinitionParser();

            $component = $this->getComponent($params["component"]);
            $componentClass = new Component($component);

            if ($componentClass->blockBranchJobs() && $this->clientWrapper->hasBranch()) {
                throw new \Keboola\Syrup\Exception\UserException('This component cannot be run in a development branch.');
            }

            // Manual config from request
            if (isset($params["configData"]) && is_array($params["configData"])) {
                $configId = null;
                if (isset($params["config"])) {
                    $configId = $params["config"];
                }
                $this->checkUnsafeConfiguration(
                    $componentClass,
                    $params['configData'],
                    $this->clientWrapper->getBranchId()
                );

                $jobDefinitionParser->parseConfigData($componentClass, $params["configData"], $configId);
                $componentConfiguration = $params["configData"];
            } else {
                // Read config from storage
                try {
                    if ($this->clientWrapper->hasBranch()) {
                        $components = new Components($this->clientWrapper->getBranchClient());
                        $configuration = $components->getConfiguration($component["id"], $params["config"]);
                    } else {
                        $configuration = $this->components->getConfiguration($component["id"], $params["config"]);
                    }

                    $this->checkUnsafeConfiguration(
                        $componentClass,
                        $configuration,
                        $this->clientWrapper->getBranchId()
                    );

                    $decryptedConfiguration = $this->encryptorFactory->getEncryptor()->decrypt($configuration);

                    $jobDefinitionParser->parseConfig($componentClass, $decryptedConfiguration);
                    $componentConfiguration = $decryptedConfiguration['configuration'];
                } catch (ClientException $e) {
                    throw new \Keboola\Syrup\Exception\UserException(
                        "Error reading configuration '{$params["config"]}': " . $e->getMessage(),
                        $e
                    );
                }
            }

            $componentClass->setImageTag(TagResolverHelper::resolveComponentImageTag(
                $params,
                $componentConfiguration,
                $componentClass
            ));
            $this->logger->info(sprintf('Using component tag: "%s"', $componentClass->getImageTag()));

            $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $this->logger);
            $jobDefinitions = $sharedCodeResolver->resolveSharedCode(
                $jobDefinitionParser->getJobDefinitions()
            );
            $variableResolver = new VariableResolver($this->clientWrapper, $this->logger);
            $jobDefinitions = $variableResolver->resolveVariables(
                $jobDefinitions,
                empty($params['variableValuesId']) ? [] : $params['variableValuesId'],
                empty($params['variableValuesData']) ? [] : $params['variableValuesData']
            );
            $usageFile = new UsageFile();
            $usageFile->setJobMapper($this->jobMapper);
            $usageFile->setFormat($componentClass->getConfigurationFormat());
            $usageFile->setJobId($job->getId());
            $outputs = $this->runner->run(
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

    private function checkUnsafeConfiguration(Component $component, array $configuration, $branchId)
    {
        if ($component->branchConfigurationsAreUnsafe() && $branchId) {
            if (empty($configuration['configuration']['runtime']['safe'])) {
                throw new \Keboola\Syrup\Exception\UserException(
                    'Is is not safe to run this configuration in a development branch. Please review the configuration.'
                );
            }
        }
    }
}
