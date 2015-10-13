<?php
/**
 * @author Ondrej Hlavacek<jakub@keboola.com>
 */


namespace Keboola\DockerBundle\Elasticsearch;

use Keboola\DockerBundle\Job\Metadata\Job;

class JobMapper extends \Keboola\Syrup\Elasticsearch\JobMapper
{
    /**
     * @param $jobId
     * @return Job|null
     */
    public function get($jobId)
    {
        $job = parent::get($jobId);
        if ($job) {
            return new Job($this->configEncryptor, $job->getData(), $job->getIndex(), $job->getType(), $job->getVersion());
        }
        return null;
    }
}
