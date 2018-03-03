<?php

namespace Keboola\DockerBundle\Service;

use Keboola\StorageApi\Client;

class StorageApiService extends \Keboola\Syrup\Service\StorageApi\StorageApiService
{
    /**
     * @return \Closure
     */
    public static function getStepPollDelayFunction()
    {
        return function($tries) {
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
        $projectFeatures = $client->verifyToken()["owner"]["features"];

        if (in_array("docker-runner-faster-polling", $projectFeatures)) {
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
            $this->setClient($clientWithFasterPolling);
        }
        return $this->client;
    }
}
