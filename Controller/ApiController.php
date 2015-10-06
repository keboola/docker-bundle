<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Job\Metadata\JobFactory;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;

class ApiController extends \Keboola\Syrup\Controller\ApiController
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

        // Create new job
        /** @var JobFactory $jobFactory */
        $jobFactory = $this->container->get('syrup.job_factory');
        $jobFactory->setStorageApiClient($this->storageApi);
        $job = $jobFactory->create('run', $params);
        $job->setLockName($job->getLockName() . '-' . $params['component']);

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
        // Get params from request
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);
        $params = $this->validateParams($params);
        $params['mode'] = 'input';

        return $this->createJobFromParams($params);
    }


    /**
     * Run docker component with the provided configuration.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function dryRunAction(Request $request)
    {
        $params = $this->getPostJson($request);
        $component = $request->get("component");
        $this->checkComponent($component);
        $params = $this->validateParams($params);
        $params['mode'] = 'dry-run';

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
            "This api call without component name is not supported, perhaps you wanted to call /{compoment}/" . $apiMethod
        );
    }


    /**
     *
     * Add component property to JSON
     *
     * @param Request $request
     * @return array
     */
    protected function getPostJson(Request $request)
    {
        $json = parent::getPostJson($request);
        if (!$request->get("concealComponent")) {
            $json["component"] = $request->get("component");
        }
        return $json;
    }

    public function encryptAction(Request $request)
    {
        return parent::encryptAction($request);
    }
}
