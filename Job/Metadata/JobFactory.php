<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;

class JobFactory extends \Keboola\Syrup\Job\Metadata\JobFactory
{
    /**
     * @var StorageApiService
     */
    protected $sapiService;

    /**
     * @var Client
     */
    protected $storageApiClient;

    public function __construct($componentName, Encryptor $encryptor, ObjectEncryptor $configEncryptor, StorageApiService $sapiService)
    {
        parent::__construct($componentName, $encryptor, $configEncryptor);
        $this->sapiService = $sapiService;
    }

    public function setStorageApiClient(Client $storageApiClient)
    {
        $this->storageApiClient = $storageApiClient;
    }

    public function create($command, array $params = [], $lockName = null)
    {
        $originalJob = parent::create($command, $params, $lockName);
        $job = new Job(
            $this->configEncryptor,
            $originalJob->getData(),
            $originalJob->getIndex(),
            $originalJob->getType(),
            $originalJob->getVersion()
        );

        $job->setStorageClient($this->sapiService->getClient());
        return $job;
    }
}
