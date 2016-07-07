<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\Syrup\Controller\ApiController;

class BaseApiController extends ApiController
{
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
