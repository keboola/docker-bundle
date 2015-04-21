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
     * @throws UserException In case of error.
     */
    private function validateParams($body)
    {
        if (!isset($body["config"]) && !isset($body["configData"])) {
            throw new UserException("Specify 'config' or 'configData'.");
        }

        if (isset($body["config"]) && isset($body["configData"])) {
            throw new UserException("Cannot specify both 'config' and 'configData'.");
        }
    }

    public function checkComponent(Request $request)
    {
        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $request->get("component")) {
                $component = $c;
                break;
            }
        }

        if (!isset($component)) {
            throw new UserException("Component '{$request->get("component")}' not found.");
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
        $this->checkComponent($request);
        $this->validateParams($this->getPostJson($request));
        return parent::runAction($request);
    }


    /**
     *  Prepare - generate configuration environment for docker image and
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
        $params['prepare'] = 1;

        $params["format"] = $request->get("format", "yaml");
        if (!in_array($params["format"], ["yaml", "json"])) {
            throw new UserException("Invalid configuration format '{$params["format"]}'.");
        }

        // check params against ES mapping
        $this->checkMappingParams($params);

        // Create new job
        /** @var JobFactory $jobFactory */
        $jobFactory = $this->container->get('syrup.job_factory');
        $jobFactory->setStorageApiClient($this->storageApi);
        $job = $jobFactory->create('run', $params);

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
     *
     * Add component property to JSON
     *
     * @param Request $request
     * @return array
     */
    protected function getPostJson(Request $request)
    {
        $json = parent::getPostJson($request);
        $json["component"] = $request->get("component");
        return $json;
    }
}
