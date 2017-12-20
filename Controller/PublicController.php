<?php

namespace Keboola\DockerBundle\Controller;

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
    public function encryptAction(Request $request)
    {
        $componentId = $request->get("componentId");
        $projectId = $request->get("projectId");
        $configurationId = $request->get("configId");
        $stackId = $this->container->getParameter("stack_id");
        if (!$componentId) {
            throw new UserException("Component Id is required.");
        }

        /** @var StorageApiService $storage */
        if (!(new ControllerHelper)->hasComponentEncryptFlag(new Client(['token' => 'dummy']), $componentId)) {
            throw new UserException("This API call is only supported for components that use the 'encrypt' flag.");
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
        } elseif ($configurationId) {
            throw new UserException("The configId parameter must be used together with projectId.");
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
}
