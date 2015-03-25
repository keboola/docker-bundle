<?php

namespace Keboola\DockerBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Keboola\Syrup\Exception\UserException;

class ApiController extends \Keboola\Syrup\Controller\ApiController
{

    public function preExecute(Request $request)
    {

        parent::preExecute($request);

        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $request->get("component")) {
                $component = $c;
            }
        }

        if (!isset($component)) {
            throw new UserException("Component '{$request->get("component")}' not found.");
        }

        // Get the formatters and change the component
//        foreach ($this->logger->getHandlers() as $handler) {
      //      if (get_class($handler->getFormatter()) == 'Keboola\\DockerBundle\\Monolog\\Formatter\\DockerBundleJsonFormatter') {
    //            $handler->getFormatter()->setAppName($component["id"]);
  //          }
//        }

 //       $this->container->get('syrup.monolog.formatter')->setAppName($component["id"]);
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function runAction(Request $request)
    {
        if (!$request->get("component")) {
            throw new UserException("Component not set.");
        }

        $body = $this->getPostJson($request);

        if (!isset($body["config"]) && !isset($body["configData"])) {
            throw new UserException("Specify 'config' or 'configData'.");
        }

        if (isset($body["config"]) && isset($body["configData"])) {
            throw new UserException("Cannot specify both 'config' and 'configData'.");
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
