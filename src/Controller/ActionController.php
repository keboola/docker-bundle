<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Service\Runner;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionController extends BaseApiController
{
    /**
     * @param $componentId
     * @return array
     */
    private function getComponent($componentId)
    {
        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $componentId) {
                $component = $c;
                break;
            }
        }

        if (!isset($component)) {
            return [];
        } else {
            return $component;
        }
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Exception
     */
    public function processAction(Request $request)
    {
        $component = $this->getComponent($request->get("component"));
        if (!$component) {
            throw new HttpException(404, "Component '{$request->get("component")}' not found");
        }
        if (!isset($component["data"]["synchronous_actions"])
            || !in_array($request->get("action"), $component["data"]["synchronous_actions"])
        ) {
            throw new HttpException(404, "Action '{$request->get("action")}' not found");
        }
        if ($request->get("action") == 'run') {
            throw new HttpException(405, "Action '{$request->get("action")}' not allowed");
        }

        $requestJsonData = $this->getPostJson($request, true);
        if (!isset($requestJsonData["configData"])) {
            throw new HttpException(400, "Attribute 'configData' missing in request body");
        }

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($request->get("component"));
        $tokenInfo = $this->storageApi->verifyToken();
        $encryptorFactory->setProjectId($tokenInfo["owner"]["id"]);
        $encryptorFactory->setStackId(parse_url($this->container->getParameter('storage_api.url'), PHP_URL_HOST));
        $configData = isset($requestJsonData["configData"]) ? $requestJsonData["configData"] : [];
        try {
            $configData = $encryptorFactory->getEncryptor()->encrypt($configData);
            $configData = $encryptorFactory->getEncryptor()->decrypt($configData);
        } catch (\Keboola\ObjectEncryptor\Exception\UserException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        if (!$this->storageApi->getRunId()) {
            $this->storageApi->setRunId($this->storageApi->generateRunId());
        }

        // Limit processing to 45 seconds
        $component['data']['process_timeout'] = 45;

        /** @var Runner $runner */
        $runner = $this->container->get('docker_bundle.runner');
        $this->container->get('logger')->info("Running Docker container '{$component['id']}'.", $configData);
        $jobDefinition = new JobDefinition($configData, new Component($component));
        $outputs = $runner->run([$jobDefinition], $request->get("action"), 'run', 0);

        $message = $outputs[0]->getProcessOutput();
        if ($message == '' || !$message) {
            throw new UserException("No response from component.");
        }

        $jsonData = json_decode($message);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new UserException("Decoding JSON response from component failed: " . json_last_error_msg(), null, ['message' => $message]);
        }
        return $this->createJsonResponse($jsonData);
    }
}
