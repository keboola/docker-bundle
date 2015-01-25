<?php

namespace Keboola\DockerBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Syrup\ComponentBundle\Exception\UserException;

class ApiController extends \Syrup\ComponentBundle\Controller\ApiController
{
    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function runAction(Request $request) {
        if (!$request->get("component")) {
            throw new UserException("Component not set.");
        }
        return parent::runAction($request);
    }

    /**
     *
     * Add component property to JSON
     *
     * @param Request $request
     * @return array
     */
    protected function getPostJson(Request $request)
    {
        $json = parent::getPostJson($request);
        $json["component"] = $request->get("component");
        return $json;
    }


}
