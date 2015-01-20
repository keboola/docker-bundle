<?php

namespace Keboola\DockerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('KeboolaDockerBundle:Default:index.html.twig', array('name' => $name));
    }
}
