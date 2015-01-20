<?php
namespace Keboola\DockerBundle\Job;
use Syrup\ComponentBundle\Job\Executor as BaseExecutor;
use Syrup\ComponentBundle\Job\Metadata\Job;

class Executor extends BaseExecutor
{
   public function execute(Job $job)
   {
      return ["message" => "Hello Job " . $job->getId()];
   }

}