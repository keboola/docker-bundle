<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;

class PublicController extends \Keboola\Syrup\Controller\PublicController
{

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function encryptAction(Request $request)
    {
        $componentId = $request->get("componentId");
        $projectId = $request->get("projectId");

        if (!$componentId) {
            return parent::encryptAction($request);
        }

        /** @var StorageApiService $storage */
        if (!(new ControllerHelper)->hasComponentEncryptFlag(new Client(['token' => 'dummy']), $componentId)) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is only supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        $encryptorClassName = ComponentWrapper::class;

        if ($projectId) {
            /** @var ComponentWrapper $cryptoWrapper */
            $cryptoWrapper = $this->container->get("syrup.encryption.component_project_wrapper");
            $cryptoWrapper->setComponentId($componentId);
            $cryptoWrapper->setProjectId($projectId);
            $encryptorClassName = ComponentProjectWrapper::class;
        } else {
            $cryptoWrapper = $this->container->get("syrup.encryption.component_wrapper");
            $cryptoWrapper->setComponentId($componentId);
        }

        /** @var ObjectEncryptor $encryptor */
        $encryptor = $this->container->get("syrup.object_encryptor");

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptor->encrypt($request->getContent(), $encryptorClassName);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptor->encrypt($params, $encryptorClassName);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type.");
        }
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function componentEncryptAction(Request $request)
    {
        $component = $request->get("component");

        /** @var StorageApiService $storage */
        if (!(new ControllerHelper)->hasComponentEncryptFlag(new Client(['token' => 'dummy']), $component)) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is only supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        /** @var ComponentWrapper $cryptoWrapper */
        $cryptoWrapper = $this->container->get("syrup.encryption.component_wrapper");
        $cryptoWrapper->setComponentId($request->get("component"));
        /** @var ObjectEncryptor $encryptor */
        $encryptor = $this->container->get("syrup.object_encryptor");

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptor->encrypt($request->getContent(), ComponentWrapper::class);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptor->encrypt($params, ComponentWrapper::class);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type.");
        }
    }
}
