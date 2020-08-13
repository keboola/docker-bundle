<?php

namespace Keboola\DockerBundle\Controller;

use Elasticsearch\Client;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\CreditsChecker;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\SharedCodeResolver;
use Keboola\DockerBundle\Docker\VariableResolver;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Job\Metadata\JobFactory;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;

class ApiController extends BaseApiController
{

    /**
     * Validate request body configuration.
     *
     * @param array $body Configuration parameters
     * @throws UserException In case of error.
     */
    private function validateParams($body)
    {
        if (isset($body["row"]) && !isset($body["config"])) {
            throw new UserException("Specify both 'row' and 'config'.");
        }
        if (!isset($body["config"]) && !isset($body["configData"])) {
            throw new UserException("Specify 'config' or 'configData'.");
        }
        if (isset($body["config"]) && isset($body["configData"])) {
            $this->logger->info("Both config and configData specified, 'config' ignored.");
        }
    }

    /**
     * Make sure that a given KBC component is valid.
     *
     * @param string $componentName KBC Component name.
     * @throw UserException in case of invalid component.
     */
    private function checkComponent($componentName)
    {
        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $componentName) {
                $component = $c;
                break;
            }
        }

        if (!isset($component)) {
            throw new UserException("Component '$componentName' not found.");
        }
    }

    /**
     * Create syrup Job from request parameters.
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Exception
     */
    private function createJobFromParams($params)
    {
        // check params against ES mapping
        $this->checkMappingParams($params);

        try {
            if (isset($params["configData"])) {
                // Encrypt configData
                /** @var ObjectEncryptorFactory $encryptorFactory */
                $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
                $encryptorFactory->setStackId(parse_url($this->container->getParameter('storage_api.url'), PHP_URL_HOST));
                $encryptorFactory->setComponentId($params["component"]);
                $tokenInfo = $this->storageApi->verifyToken();
                $encryptorFactory->setProjectId($tokenInfo["owner"]["id"]);
                $params["configData"] = $encryptorFactory->getEncryptor()->encrypt($params["configData"]);
            }

            // Create new job
            /** @var JobFactory $jobFactory */
            $jobFactory = $this->container->get('syrup.job_factory');
            $job = $jobFactory->create('run', $params);
            $job->setEncryptor($this->container->get("docker_bundle.object_encryptor_factory")->getEncryptor());

            // Lock name contains component id and configuration id or random string
            $lockName = $job->getLockName() . '-' . $params['component'];
            if (isset($params["config"]) && is_scalar($params["config"])) {
                $lockName .= "-" . $params["config"];
            } else {
                $lockName .= "-" . uniqid();
            }
            if (isset($params["config"]) && !is_scalar($params["config"])) {
                throw new UserException("Body parameter 'config' is not a number.");
            }
            $job->setLockName($lockName);
        } catch (ClientException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        // Add job to Elasticsearch
        try {
            /** @var JobMapper $jobMapper */
            $jobMapper = $this->container->get('syrup.elasticsearch.current_component_job_mapper');
            $jobId = $jobMapper->create($job);
        } catch (ApplicationException $e) {
            throw new ApplicationException("Failed to create job", $e);
        }

        // Add job to SQS
        $queueName = 'default';
        $queueParams = $this->container->getParameter('queue');

        if (isset($queueParams['sqs'])) {
            $queueName = $queueParams['sqs'];
        }
        $messageId = $this->enqueue($jobId, $queueName);

        $this->logger->info('Job created', [
            'sqsQueue' => $queueName,
            'sqsMessageId' => $messageId,
            'job' => $job->getLogData()
        ]);

        // Response with link to job resource
        return $this->createJsonResponse([
            'id'        => $jobId,
            'url'       => $this->getJobUrl($jobId),
            'status'    => $job->getStatus()
        ], 202);
    }

    /**
     *  Debug - create a snapshot of data folder before and after every container, do not perform output mapping.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function debugAction(Request $request)
    {
        // Get params from request
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);
        $this->validateParams($params);
        $params['mode'] = Runner::MODE_DEBUG;
        return $this->createJobFromParams($params);
    }

    /**
     * Run docker component with the provided configuration.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function runAction(Request $request)
    {
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);
        $this->validateParams($params);
        $params['mode'] = 'run';
        $this->checkCredits($request);
        return $this->createJobFromParams($params);
    }

    private function checkCredits(Request $request)
    {
        $creditsChecker = new CreditsChecker($this->storageApi);
        if (!$creditsChecker->hasCredits()) {
            throw new UserException('You do not have credits to run a job');
        }
    }

    /**
     * Run docker component with the provided configuration and specified image tag.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function runTagAction(Request $request)
    {
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);
        $this->validateParams($params);
        $params['mode'] = 'run';
        $params['tag'] = $request->get('tag');
        $this->checkCredits($request);
        return $this->createJobFromParams($params);
    }

    /**
     * Run docker component with the provided configuration.
     *
     * @param Request $request
     * @return void
     */
    public function disabledAction(Request $request)
    {
        $apiMethod = substr($request->getPathInfo(), strrpos($request->getPathInfo(), '/') + 1);
        throw new UserException(
            "This api call without component name is not supported, perhaps you wanted to call /{component}/" .
            $apiMethod
        );
    }


    /**
     * Get body of POST request as parsed JSON.
     *
     * @param Request $request
     * @param bool $assoc If true, return as associative array, if false return as stdClass
     * @return array|\stdClass
     */
    protected function getPostJson(Request $request, $assoc = true)
    {
        $json = parent::getPostJson($request, $assoc);
        if (is_array($json)) {
            $json["component"] = $request->get("component");
        } else {
            $json->component = $request->get("component");
        }
        return $json;
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Keboola\ObjectEncryptor\Exception\ApplicationException
     */
    public function migrateConfigAction(Request $request)
    {
        $componentId = $request->get("componentId");
        $projectId = $request->get("projectId");
        $stackId = parse_url($this->container->getParameter("storage_api.url"), PHP_URL_HOST);
        if (!$componentId || !$stackId) {
            throw new UserException("Stack id and component id must be entered.");
        }

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $configData = $request->getContent();
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $configData = $this->getPostJson($request, false);
        } else {
            throw new UserException("Incorrect Content-Type.");
        }

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId($componentId);
        if ($projectId) {
            $encryptorFactory->setProjectId($projectId);
            $wrapperClass = $encryptorFactory->getEncryptor()->getRegisteredProjectWrapperClass();
        } else {
            $wrapperClass = $encryptorFactory->getEncryptor()->getRegisteredComponentWrapperClass();
        }

        try {
            $configDataMigrated = $encryptorFactory->getEncryptor()->decrypt($configData);
            $configDataMigrated = $encryptorFactory->getEncryptor()->encrypt($configDataMigrated, $wrapperClass);
        } catch (\Keboola\ObjectEncryptor\Exception\UserException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            return $this->createResponse($configDataMigrated, 200, ["Content-Type" => "text/plain"]);
        } else {
            return $this->createJsonResponse($configDataMigrated, 200, ["Content-Type" => "application/json"]);
        }
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function configurationResolveAction(Request $request)
    {
        try {
            $body = $this->getPostJson($request);
            if (empty($body['componentId'])) {
                throw new UserException('Missing "componentId" parameter in request body.');
            }
            $componentId = $body['componentId'];
            if (empty($body['configId'])) {
                throw new UserException('Missing "configId" parameter in request body.');
            }
            $configId = $body['configId'];
            if (empty($body['configVersion'])) {
                throw new UserException('Missing "configVersion" parameter in request body.');
            }
            $configVersion = $body['configVersion'];
            if (!empty($body['variableValuesId'])) {
                $variableValuesId = $body['variableValuesId'];
            } else {
                $variableValuesId = null;
            }
            if (!empty($body['variableValuesData']) && is_array($body['variableValuesData'])) {
                $variableValuesData = $body['variableValuesData'];
            } else {
                $variableValuesData = [];
            }

            // get the configuration from storage
            $components = new Components($this->storageApi);
            $configDataVersion = $components->getConfigurationVersion($componentId, $configId, $configVersion);
            // configuration version doesn't contain configuration id & state and we need them
            // https://keboola.slack.com/archives/CFVRE56UA/p1596785471369600
            $configDataVersion['id'] = $configId;
            $configDataVersion['state'] = [];
            foreach ($configDataVersion['rows'] as &$row) {
                $row['state'] = [];
            }

            $jobDefinitionParser = new JobDefinitionParser();
            $projectId = $this->storageApi->verifyToken()['owner']['id'];
            $stackId = parse_url($this->container->getParameter('storage_api.url'), PHP_URL_HOST);
            /** @var ObjectEncryptorFactory $encryptorFactory */
            $encryptorFactory = $this->container->get('docker_bundle.object_encryptor_factory');
            $encryptorFactory->setStackId($stackId);
            $encryptorFactory->setComponentId($componentId);
            $encryptorFactory->setProjectId($projectId);
            $componentClass = new Component($this->getComponent($componentId));
            $jobDefinitionParser->parseConfig($componentClass, $encryptorFactory->getEncryptor()->decrypt($configDataVersion));
            $sharedCodeResolver = new SharedCodeResolver($this->storageApi, $this->logger);
            $jobDefinitions = $sharedCodeResolver->resolveSharedCode(
                $jobDefinitionParser->getJobDefinitions()
            );
            $variableResolver = new VariableResolver($this->storageApi, $this->logger);
            $jobDefinitions = $variableResolver->resolveVariables($jobDefinitions, $variableValuesId, $variableValuesData);
            /** @var JobDefinition[] $jobDefinitions */
            if ($configDataVersion['rows']) {
                foreach ($jobDefinitions as $index => $jobDefinition) {
                    $configDataVersion['rows'][$index]['configuration'] = $jobDefinition->getConfiguration();
                }
            } else {
                $configDataVersion['configuration'] = $jobDefinitions[0]->getConfiguration();
            }
        } catch (\Keboola\DockerBundle\Exception\UserException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        return $this->createJsonResponse($configDataVersion, 200, ['Content-Type' => 'application/json']);
    }

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
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function projectStatsAction(Request $request)
    {
        $tokenInfo = $this->storageApi->verifyToken();
        $projectId = $tokenInfo['owner']['id'];
        /** @var Client $client */
        $client = $this->container->get('syrup.elasticsearch.client');
        $data = $client->search([
            'body' => [
                'query' => [
                    'constant_score' => [
                        'filter' => [
                            'bool' => [
                                'must' => [
                                    'term' => [
                                        'project.id' => $projectId,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'jobs' => [
                        'sum' => [
                            'field' => 'durationSeconds',
                        ],
                    ],
                ],
            ],
        ]);

        $response = ['jobs' => ['durationSum' => $data['aggregations']['jobs']['value']]];
        return $this->createJsonResponse($response, 200, ['Content-Type' => 'application/json']);
    }


    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function projectDailyStatsAction(Request $request)
    {
        $tokenInfo = $this->storageApi->verifyToken();
        $projectId = $tokenInfo['owner']['id'];
        $fromDate = $request->get('fromDate');
        $toDate = $request->get('toDate');
        $timezoneOffset = $request->get('timezoneOffset');
        if (empty($fromDate)) {
            throw new UserException('Missing "fromDate" query parameter.');
        }
        if (empty($toDate)) {
            throw new UserException('Missing "toDate" query parameter.');
        }
        if (empty($timezoneOffset)) {
            throw new UserException('Missing "timezoneOffset" query parameter.');
        }
        /** @var Client $client */
        $client = $this->container->get('syrup.elasticsearch.client');
        $data = $client->search([
            'body' => [
                'query' => [
                    'constant_score' => [
                        'filter' => [
                            'bool' => [
                                'must' => [
                                    [
                                        'term' => [
                                            'project.id' => $projectId,
                                        ],
                                    ],
                                    [
                                        'range' => [
                                            'endTime' => [
                                                'gte' => $fromDate,
                                                'lte' => $toDate,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'aggs' => [
                    'jobs_over_time' => [
                        'date_histogram' => [
                            'field' => 'endTime',
                            "format" => 'yyyy-MM-dd',
                            'interval' => 'day',
                            'time_zone' => $timezoneOffset,
                        ],
                        'aggs' => [
                            'jobs' => [
                                'sum' => [
                                    'field' => 'durationSeconds',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = [];
        foreach ($data['aggregations']['jobs_over_time']['buckets'] as $bucket) {
            $result[] = ['date' => $bucket['key_as_string'], 'durationSum' => $bucket['jobs']['value']];
        }

        $response = ['jobs' => $result];
        return $this->createJsonResponse($response, 200, ['Content-Type' => 'application/json']);
    }
}
