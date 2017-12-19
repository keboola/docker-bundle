<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\ObjectEncryptor\Exception\ApplicationException;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentWrapper as LegacyComponentWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\ComponentWrapper;
use Keboola\ObjectEncryptor\Wrapper\ConfigurationWrapper;
use Keboola\ObjectEncryptor\Wrapper\ProjectWrapper;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;

class PublicController extends \Keboola\Syrup\Controller\PublicController
{
    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Keboola\ObjectEncryptor\Exception\ApplicationException
     */
    public function encryptLegacyAction(Request $request)
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

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($componentId);
        if ($projectId) {
            $encryptorFactory->setProjectId($projectId);
            $encryptorClass = ComponentProjectWrapper::class;
        } else {
            $encryptorClass = LegacyComponentWrapper::class;
        }

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($request->getContent(), $encryptorClass);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($params, $encryptorClass);
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
    public function encryptAction(Request $request)
    {
        $componentId = $request->get("componentId");
        $projectId = $request->get("projectId");
        $configurationId = $request->get("configId");
        $stackId = $this->container->getParameter("stack_id");

        if (!$componentId) {
            throw new UserException("Component Id is required.");
        }
        if (!$stackId) {
            throw new ApplicationException("Stack Id is required.");
        }

        /** @var StorageApiService $storage */
        if (!(new ControllerHelper)->hasComponentEncryptFlag(new Client(['token' => 'dummy']), $componentId)) {
            return $this->createJsonResponse([
                'status'    => 'error',
                'message'    => 'This API call is only supported for components that use the \'encrypt\' flag.',
            ], 400);
        }

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($componentId);
        $encryptorFactory->setStackId($stackId);
        if ($projectId && $configurationId) {
            $encryptorFactory->setProjectId($projectId);
            $encryptorFactory->setConfigurationId($configurationId);
            $wrapperClassName = ConfigurationWrapper::class;
        } elseif ($projectId) {
            $encryptorFactory->setProjectId($projectId);
            $wrapperClassName = ProjectWrapper::class;
        } else {
            $wrapperClassName = ComponentWrapper::class;
        }

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($request->getContent(), $wrapperClassName);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($params, $wrapperClassName);
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

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($request->get("component"));

        $contentTypeHeader = $request->headers->get("Content-Type");
        if (!is_string($contentTypeHeader)) {
            throw new UserException("Incorrect Content-Type.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($request->getContent(), LegacyComponentWrapper::class);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request, false);
            $encryptedValue = $encryptorFactory->getEncryptor()->encrypt($params, LegacyComponentWrapper::class);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type.");
        }
    }
}
