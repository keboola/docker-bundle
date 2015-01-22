<?php

namespace Keboola\DockerBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Syrup\ComponentBundle\Exception\UserException;

class ApiController extends \Syrup\ComponentBundle\Controller\ApiController
{
    public function runAction(Request $request) {
        if (!$request->get("component")) {
            throw new UserException("Component not set.");
        }
        var_dump($request->get("component"));
        var_dump($request->getContent());
        die();
    }
}
