<?php

namespace Keboola\DockerBundle\Controller;

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
        $component = $request->get("component");
        if (!$component) {
            return parent::encryptAction($request);
        }

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
            throw new UserException("Incorrect Content-Type header.");
        }

        if (strpos(strtolower($contentTypeHeader), "text/plain") !== false) {
            $encryptedValue = $encryptor->encrypt($request->getContent(), ComponentWrapper::class);
            return $this->createResponse($encryptedValue, 200, ["Content-Type" => "text/plain"]);
        } elseif (strpos(strtolower($contentTypeHeader), "application/json") !== false) {
            $params = $this->getPostJson($request);
            $encryptedValue = $encryptor->encrypt($params, ComponentWrapper::class);
            return $this->createJsonResponse($encryptedValue, 200, ["Content-Type" => "application/json"]);
        } else {
            throw new UserException("Incorrect Content-Type header.");
        }
    }
}
