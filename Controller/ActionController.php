<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Service\Runner;
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

        // set params for component_project_wrapper
        $cryptoWrapper = $this->container->get("syrup.encryption.component_project_wrapper");
        $cryptoWrapper->setComponentId($request->get("component"));
        $tokenInfo = $this->storageApi->verifyToken();
        $cryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);

        // set params for component_project_wrapper
        $cryptoWrapper = $this->container->get("syrup.encryption.component_wrapper");
        $cryptoWrapper->setComponentId($request->get("component"));

        $configData = $requestJsonData["configData"];
        if (in_array("encrypt", $component["flags"])) {
            $configData = $this->container->get('syrup.object_encryptor')->encrypt($configData);
            $configData = $this->container->get('syrup.object_encryptor')->decrypt($configData);
        }

        $state = [];
        if (!$this->storageApi->getRunId()) {
            $this->storageApi->setRunId($this->storageApi->generateRunId());
        }

        // Limit processing to 30 seconds
        $component['data']['process_timeout'] = 30;

        /** @var Runner $runner */
        $runner = $this->container->get('docker_bundle.runner');
        $runner->setFeatures($tokenInfo["owner"]["features"]);
        $this->container->get('logger')->info("Running Docker container '{$component['id']}'.", $configData);
        $message = $runner->run($component, null, $configData, $state, $request->get("action"), 'run', 0);

        if ($message == '' || !$message) {
            throw new UserException("No response from component.");
        }

        $jsonData = json_decode($message);
        if (!$jsonData) {
            throw new UserException("Decoding JSON response from component failed");
        }
        return $this->createJsonResponse($jsonData);
    }
}
