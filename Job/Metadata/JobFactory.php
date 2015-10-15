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

class JobFactory
{
    /**
     * @var Encryptor
     */
    protected $encryptor;

    /**
     * @var ObjectEncryptor
     */
    protected $configEncryptor;

    /**
     * @var StorageApiService
     */
    protected $sapiService;

    protected $componentName;

    /**
     * @var Client
     */
    protected $storageApiClient;

    public function __construct($componentName, Encryptor $encryptor, ObjectEncryptor $configEncryptor, StorageApiService $sapiService)
    {
        $this->encryptor = $encryptor;
        $this->configEncryptor = $configEncryptor;
        $this->componentName = $componentName;
        $this->sapiService = $sapiService;
    }

    public function setStorageApiClient(Client $storageApiClient)
    {
        $this->storageApiClient = $storageApiClient;
    }

    public function create($command, array $params = [], $lockName = null)
    {
        if (!$this->storageApiClient) {
            throw new \Exception('Storage API client must be set');
        }

        $tokenData = $this->storageApiClient->verifyToken();
        $job = new Job($this->configEncryptor, [
                'id' => $this->storageApiClient->generateId(),
                'runId' => $this->storageApiClient->getRunId(),
                'project' => [
                    'id' => $tokenData['owner']['id'],
                    'name' => $tokenData['owner']['name']
                ],
                'token' => [
                    'id' => $tokenData['id'],
                    'description' => $tokenData['description'],
                    'token' => $this->encryptor->encrypt($this->storageApiClient->getTokenString())
                ],
                'component' => $this->componentName,
                'command' => $command,
                'params' => $params,
                'process' => [
                    'host' => gethostname(),
                    'pid' => getmypid()
                ],
                'nestingLevel' => 0,
                'createdTime' => date('c')
            ], null, null, null);
        if ($lockName) {
            $job->setLockName($lockName);
        }
        $job->setStorageClient($this->sapiService->getClient());
        return $job;
    }
}
