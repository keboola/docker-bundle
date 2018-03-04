<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\ComponentWrapper;
use Keboola\ObjectEncryptor\Wrapper\ProjectWrapper;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Symfony\Component\HttpFoundation\JsonResponse;
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


    private function validateComponent(Request $request)
    {
        /** @var StorageApiService $storage */
        $storage = $this->container->get("docker_bundle.storage_api");

        // Get params from request
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);

        if ((new ControllerHelper())->hasComponentEncryptFlag($storage->getClient(), $params["component"])) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is not supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        $this->validateParams($params);
        return $params;
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
        /** @var StorageApiService $storage */
        $storage = $this->container->get("docker_bundle.storage_api");

        // check params against ES mapping
        $this->checkMappingParams($params);

        // Encrypt configData for encrypt flagged components
        try {
            if ((new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $params["component"])
                && isset($params["configData"])
            ) {
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
     *  Sandbox - generate configuration and environment and
     *  store it in KBC Storage.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function sandboxAction(Request $request)
    {
        // Get params from request
        $params = $this->getPostJson($request);
        $this->validateParams($params);
        $params['mode'] = 'sandbox';

        # TODO deprecated, remove later
        $params["format"] = $request->get("format", "yaml");
        if (!in_array($params["format"], ["yaml", "json"])) {
            throw new UserException("Invalid configuration format '{$params["format"]}'.");
        }

        return $this->createJobFromParams($params);
    }


    /**
     *  Prepare - generate configuration and environment for an existing docker image and
     *  store it in KBC Storage.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function inputAction(Request $request)
    {
        $ret = $this->validateComponent($request);
        if (is_a($ret, JsonResponse::class)) {
            return $ret;
        } else {
            $ret['mode'] = 'input';
            return $this->createJobFromParams($ret);
        }
    }


    /**
     * Run docker component with the provided configuration.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function dryRunAction(Request $request)
    {
        $ret = $this->validateComponent($request);
        if (is_a($ret, JsonResponse::class)) {
            return $ret;
        } else {
            $ret['mode'] = 'dry-run';
            return $this->createJobFromParams($ret);
        }
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

        return $this->createJobFromParams($params);
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
    public function encryptConfigAction(Request $request)
    {
        $this->logger->warn("Using deprecated encryptConfig call.");
        /** @var StorageApiService $storage */
        $storage = $this->container->get("docker_bundle.storage_api");

        $component = $request->get("component");
        if (!(new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $component)) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is only supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($request->get("component"));
        $tokenInfo = $this->storageApi->verifyToken();
        $encryptorFactory->setProjectId($tokenInfo["owner"]["id"]);

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($request->getContent(), ComponentProjectWrapper::class);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($params, ComponentProjectWrapper::class);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type.");
        }
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Keboola\ObjectEncryptor\Exception\ApplicationException
     */
    public function saveConfigAction(Request $request)
    {
        $this->logger->warn("Using deprecated saveConfig call.");
        /** @var StorageApiService $storage */
        $storage = $this->container->get("docker_bundle.storage_api");

        $components = new Components($this->storageApi);
        $options = new Configuration();
        $options->setComponentId($request->get("component"));
        $options->setConfigurationId($request->get("configId"));

        if ($request->get("configuration")) {
            $configuration = json_decode($request->get("configuration"));
            if ((new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $request->get("component"))) {
                /** @var ObjectEncryptorFactory $encryptorFactory */
                $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
                $encryptorFactory->setComponentId($request->get("component"));
                $tokenInfo = $this->storageApi->verifyToken();
                $encryptorFactory->setProjectId($tokenInfo["owner"]["id"]);
                $configuration = $encryptorFactory->getEncryptor()->encrypt($configuration, ComponentProjectWrapper::class);
            }
            $options->setConfiguration($configuration);
        }

        if ($request->get("changeDescription")) {
            $options->setChangeDescription($request->get("changeDescription"));
        }

        if ($request->get("name")) {
            $options->setName($request->get("name"));
        }

        if ($request->get("description")) {
            $options->setDescription($request->get("description"));
        }

        if ($request->get("state")) {
            $options->setState($request->get("state"));
        }

        try {
            $response = $components->updateConfiguration($options);
        } catch (ClientException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        return $this->createJsonResponse($response, 200, ["Content-Type" => "application/json"]);
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
        if (!(new ControllerHelper)->hasComponentEncryptFlag($this->storageApi, $componentId)) {
            throw new UserException("This API call is only supported for components that use the 'encrypt' flag.");
        }
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
            $wrapperClass = ProjectWrapper::class;
        } else {
            $wrapperClass = ComponentWrapper::class;
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
}
