<?php

namespace Keboola\DockerBundle\Service;

use Keboola\StorageApi\Client;

class StorageApiService extends \Keboola\Syrup\Service\StorageApi\StorageApiService
{
    private $fasterPollingClient = null;

    /**
     * @return \Closure
     */
    public static function getStepPollDelayFunction()
    {
        return function ($tries) {
            switch ($tries) {
                case ($tries < 15):
                    return 1;
                case ($tries < 30):
                    return 2;
                default:
                    return 5;
            }
        };
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        $client = parent::getClient();
        if (!$this->fasterPollingClient) {
            $clientWithFasterPolling = new Client(
                [
                    'token' => $client->token,
                    'url' => $client->getApiUrl(),
                    'userAgent' => $client->getUserAgent(),
                    'backoffMaxTries' => $client->getBackoffMaxTries(),
                    'jobPollRetryDelay' => self::getStepPollDelayFunction()
                ]
            );
            if ($client->getRunId()) {
                $clientWithFasterPolling->setRunId($client->getRunId());
            }
            $this->setFasterPollingClient($clientWithFasterPolling);
        }
        return $this->fasterPollingClient;
    }

    public function setFasterPollingClient(Client $client)
    {
        $this->fasterPollingClient = $this->verifyClient($client);
    }
}
