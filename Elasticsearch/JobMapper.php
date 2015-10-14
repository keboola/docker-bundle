<?php
/**
 * @author Ondrej Hlavacek<jakub@keboola.com>
 */


namespace Keboola\DockerBundle\Elasticsearch;

use Keboola\DockerBundle\Job\Metadata\Job;
use Keboola\Syrup\Elasticsearch\ComponentIndex;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Elasticsearch\Client;
use Keboola\Syrup\Service\ObjectEncryptor;

class JobMapper extends \Keboola\Syrup\Elasticsearch\JobMapper
{

    /**
     * @var StorageApiService
     */
    protected $sapiService;

    public function __construct(Client $client, ComponentIndex $index, ObjectEncryptor $configEncryptor, StorageApiService $sapiService, $logger = null, $rootDir = null)
    {
        $this->sapiService = $sapiService;
        parent::__construct($client, $index, $configEncryptor, $logger, $rootDir);
    }


    /**
     * @param $jobId
     * @return Job|null
     */
    public function get($jobId)
    {
        $job = parent::get($jobId);
        if ($job) {
            return new Job(
                $this->configEncryptor,
                $this->sapiService->getClient(),
                $job->getData(),
                $job->getIndex(),
                $job->getType(),
                $job->getVersion()
            );
        }
        return null;
    }
}
