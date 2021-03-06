<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
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
        $diff = array_diff(array_keys($request->query->all()), ['componentId', 'projectId', 'configId']);
        if ($diff) {
            throw new UserException("Unknown parameter: '" . implode(',', $diff) . "'.");
        }
        $stackId = parse_url($this->container->getParameter("storage_api.url"), PHP_URL_HOST);
        if (!$componentId) {
            throw new UserException("Component Id is required.");
        }

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = $this->container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId($componentId);
        $encryptorFactory->setStackId($stackId);
        if ($projectId && $configurationId) {
            $encryptorFactory->setProjectId($projectId);
            $encryptorFactory->setConfigurationId($configurationId);
            $wrapperClassName = $encryptorFactory->getEncryptor()->getRegisteredConfigurationWrapperClass();
        } elseif ($projectId) {
            $encryptorFactory->setProjectId($projectId);
            $wrapperClassName = $encryptorFactory->getEncryptor()->getRegisteredProjectWrapperClass();
        } elseif ($configurationId) {
            throw new UserException("The configId parameter must be used together with projectId.");
        } else {
            $wrapperClassName = $encryptorFactory->getEncryptor()->getRegisteredComponentWrapperClass();
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
