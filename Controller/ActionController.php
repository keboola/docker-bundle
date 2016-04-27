<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationsOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\ObjectEncryptor;
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
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function processAction(Request $request)
    {
        $component = $this->getComponent($request->get("component"));
        if (!$component) {
            throw new HttpException(404, "Component '{$request->get("component")}' not found.");
        }
        /*
        if (!isset($component["data"]["synchronous_actions"]) || !in_array($request->get("action"), $component["data"]["synchronous_actions"])) {
            throw new HttpException(404, "Action '{$request->get("action")}' not found.");
        }
        */

        if (in_array("encrypt", $component["flags"])) {
            $configData = $this->container->get('syrup.object_encryptor')->decrypt($this->getPostJson($request, true));
        } else {
            $configData = $this->getPostJson($request, true);
        }

        $configId = uniqid();
        $state = [];

        $tokenInfo = $this->storageApi->verifyToken();

        try {
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

            //$this->log->info("Running Docker container '{$component['id']}'.", $configData);

            $containerId = $component["id"] . "-" . $this->storageApi->getRunId();
            $image = Image::factory($this->container->get('syrup.object_encryptor'), $this->container->get('logger'), $component["data"]);

            // Async actions force streaming logs off!
            $image->setStreamingLogs(false);

            $container = new Container($image, $this->container->get('logger'));
            $executor->initialize($container, $configData, $state, false, $request->get("action"));
            $message = $executor->run($container, $containerId, $tokenInfo, $configId);
            $executor->storeOutput($container, $state);
            //$this->log->info("Docker container '{$component['id']}' finished.");
        } catch (UserException $e) {
            // TODO LOG!
            throw $e;
            return $this->createJsonResponse(["status" => "error", "message" => "Action '{$request->get("action")}' failed: {$e->getMessage()}"], 400);
        } catch (ApplicationException $e) {
            // TODO LOG!
            throw $e;
            return $this->createJsonResponse(["status" => "error", "message" => "Action '{$request->get("action")}' failed."], 500);
        } catch (\Exception $e) {
            // TODO LOG!
            throw $e;
            return $this->createJsonResponse(["status" => "error", "message" => "Action '{$request->get("action")}' failed."], 500);
        }

        return $this->createJsonResponse(["status" => "ok", "payload" => $message], 200);
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
