<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\OAuthV2Api\Credentials;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionController extends \Keboola\Syrup\Controller\ApiController
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
     * @param $componentId
     * @return bool
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
            return false;
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
        if (!isset($component["data"]["synchronous_actions"]) || !in_array($request->get("action"), $component["data"]["synchronous_actions"])) {
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
            $configData = $this->container->get('syrup.object_encryptor')->decrypt($configData);
        }

        $configId = uniqid();
        $state = [];

        $tokenInfo = $this->storageApi->verifyToken();

        if (!$this->storageApi->getRunId()) {
            $this->storageApi->generateRunId();
        }
        $processor = new DockerProcessor($component['id']);
        // attach the processor to all handlers and channels
        $this->container->get('logger')->pushProcessor([$processor, 'processRecord']);

        $oauthCredentialsClient = new Credentials($this->storageApi->getTokenString());
        $oauthCredentialsClient->enableReturnArrays(true);
        $executor = new Executor($this->storageApi, $this->container->get('logger'), $oauthCredentialsClient, $this->temp->getTmpFolder());
        $executor->setComponentId($component["id"]);

        $this->container->get('logger')->info("Running Docker container '{$component['id']}'.", $configData);

        $containerId = $component["id"] . "-" . $this->storageApi->getRunId();

        $image = Image::factory($this->container->get('syrup.object_encryptor'), $this->container->get('logger'), $component["data"]);

        // Async actions force streaming logs off!
        $image->setStreamingLogs(false);

        // Limit processing to 30 seconds
        $image->setProcessTimeout(30);

        $container = new Container($image, $this->container->get('logger'));
        $executor->initialize($container, $configData, $state, false, $request->get("action"));
        $message = $executor->run($container, $containerId, $tokenInfo, $configId);
        $this->container->get('logger')->info("Docker container '{$component['id']}' finished.");

        if ($message == '' || !$message) {
            throw new UserException("No response from component.");
        }

        $jsonData = json_decode($message);
        if (!$jsonData) {
            throw new UserException("Decoding JSON response from component failed");
        }
        return $this->createJsonResponse($jsonData);
    }

    /**
     *
     * hide component param from response
     *
     * @param null $data
     * @param string $status
     * @param array $headers
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function createJsonResponse($data = null, $status = '200', $headers = array())
    {
        if (is_array($data) && array_key_exists("component", $data)) {
            unset($data["component"]);
        } elseif (is_object($data) && property_exists($data, 'component')) {
            unset($data->component);
        }
        return parent::createJsonResponse($data, $status, $headers);
    }
}
