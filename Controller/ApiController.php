<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
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
     * @return array Validated configuration parameters.
     * @throws UserException In case of error.
     */
    private function validateParams($body)
    {
        if (!isset($body["config"]) && !isset($body["configData"])) {
            throw new UserException("Specify 'config' or 'configData'.");
        }

        if (isset($body["config"]) && isset($body["configData"])) {
            $this->logger->info("Both config and configData specified, 'config' ignored.");
            unset($body["config"]);
        }
        return $body;
    }


    private function validateComponent(Request $request)
    {
        /** @var StorageApiService $storage */
        $storage = $this->container->get("syrup.storage_api");

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

        return $this->validateParams($params);
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
        $storage = $this->container->get("syrup.storage_api");

        // check params against ES mapping
        $this->checkMappingParams($params);

        // Encrypt configData for encrypt flagged components
        try {
            if ((new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $params["component"])
                && isset($params["configData"])
            ) {
                $cryptoWrapper = $this->container->get("syrup.encryption.component_project_wrapper");
                $cryptoWrapper->setComponentId($params["component"]);
                $tokenInfo = $this->storageApi->verifyToken();
                $cryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
                $encryptor = $this->container->get("syrup.object_encryptor");
                $params["configData"] = $encryptor->encrypt($params["configData"]);
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
        } catch (\Exception $e) {
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
        $params = $this->validateParams($params);
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
     * @return \Symfony\Component\HttpFoundation\Response
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
     */
    public function encryptConfigAction(Request $request)
    {
        /** @var StorageApiService $storage */
        $storage = $this->container->get("syrup.storage_api");

        $component = $request->get("component");
        if (!(new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $component)) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is only supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        /** @var ComponentProjectWrapper $cryptoWrapper */
        $cryptoWrapper = $this->container->get("syrup.encryption.component_project_wrapper");
        $cryptoWrapper->setComponentId($request->get("component"));
        $tokenInfo = $this->storageApi->verifyToken();
        $cryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor = $this->container->get("syrup.object_encryptor");

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type header.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptor->encrypt($request->getContent(), ComponentProjectWrapper::class);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptor->encrypt($params, ComponentProjectWrapper::class);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type header.");
        }
    }

    public function saveConfigAction(Request $request)
    {
        /** @var StorageApiService $storage */
        $storage = $this->container->get("syrup.storage_api");

        $components = new Components($this->storageApi);
        $options = new Configuration();
        $options->setComponentId($request->get("component"));
        $options->setConfigurationId($request->get("configId"));

        if ($request->get("configuration")) {
            $configuration = json_decode($request->get("configuration"));
            if ((new ControllerHelper)->hasComponentEncryptFlag($storage->getClient(), $request->get("component"))) {
                $cryptoWrapper = $this->container->get("syrup.encryption.component_project_wrapper");
                $cryptoWrapper->setComponentId($request->get("component"));
                $tokenInfo = $this->storageApi->verifyToken();
                $cryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
                $encryptor = $this->container->get("syrup.object_encryptor");
                $configuration = $encryptor->encrypt($configuration, ComponentProjectWrapper::class);
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
}
