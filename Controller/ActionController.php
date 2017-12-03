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

        $configData = isset($requestJsonData["configData"]) ? $requestJsonData["configData"] : [];
        if (in_array("encrypt", $component["flags"])) {
            $configData = $encryptorFactory->getEncryptor()->encrypt($configData);
            $configData = $encryptorFactory->getEncryptor()->decrypt($configData);
        }

        if (!$this->storageApi->getRunId()) {
            $this->storageApi->setRunId($this->storageApi->generateRunId());
        }

        // Limit processing to 30 seconds
        $component['data']['process_timeout'] = 30;

        /** @var Runner $runner */
        $runner = $this->container->get('docker_bundle.runner');
        $runner->setFeatures($tokenInfo["owner"]["features"]);
        $this->container->get('logger')->info("Running Docker container '{$component['id']}'.", $configData);
        $jobDefinition = new JobDefinition($configData, new Component($component));
        $outputs = $runner->run([$jobDefinition], $request->get("action"), 'run', 0);

        $message = $outputs[0]->getProcessOutput();
        if ($message == '' || !$message) {
            throw new UserException("No response from component.");
        }

        $jsonData = json_decode($message);
        if (!$jsonData) {
            throw new UserException("Decoding JSON response from component failed", null, ['message' => $message]);
        }
        return $this->createJsonResponse($jsonData);
    }
}
