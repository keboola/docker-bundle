<?php
/**
 * @author Ondrej Hlavacek<jakub@keboola.com>
 */


namespace Keboola\DockerBundle\Elasticsearch;

use Keboola\DockerBundle\Job\Metadata\Job;
use Keboola\Syrup\Elasticsearch\ComponentIndex;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\Syrup\Exception\NoRequestException;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Elasticsearch\Client;
use Keboola\Syrup\Service\ObjectEncryptor;

class JobMapper extends \Keboola\Syrup\Elasticsearch\JobMapper
{

    /**
     * @var StorageApiService
     */
    protected $sapiService;

    /**
     * @var Encryptor
     */
    protected $encryptor;

    public function __construct(Client $client, ComponentIndex $index, ObjectEncryptor $configEncryptor, StorageApiService $sapiService, Encryptor $encryptor, $logger = null, $rootDir = null)
    {
        $this->sapiService = $sapiService;
        $this->encryptor = $encryptor;
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
            $job = new Job(
                $this->configEncryptor,
                $job->getData(),
                $job->getIndex(),
                $job->getType(),
                $job->getVersion()
            );
            try {
                $client = $this->sapiService->getClient();
            } catch (NoRequestException $e) {
                // When called from CLI request is missing
                $client = new \Keboola\StorageApi\Client([
                    'token' => $this->encryptor->decrypt($job->getToken()['token'])
                ]);
                $client->setRunId($job->getRunId());
            }
            $job->setStorageClient($client);
            return $job;
        }
        return null;
    }
}
