<?php

namespace Keboola\DockerBundle\Service;

use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class StorageApiService extends \Keboola\Syrup\Service\StorageApi\StorageApiService
{
    /**
     * @var Client
     */
    private $fasterPollingClient = null;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Client
     */
    private $clientWithoutLogger = null;

    public function __construct(RequestStack $requestStack, $storageApiUrl = 'https://connection.keboola.com', LoggerInterface $logger = null)
    {
        parent::__construct($requestStack, $storageApiUrl);
        $this->logger = $logger;
    }

    /**
     * @return \Closure
     */
    public function getStepPollDelayFunction()
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

    public function getLogger()
    {
        return $this->logger;
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
                    'jobPollRetryDelay' => $this->getStepPollDelayFunction(),
                    'logger' => $this->logger
                ]
            );
            if ($client->getRunId()) {
                $clientWithFasterPolling->setRunId($client->getRunId());
            }
            $this->setFasterPollingClient($clientWithFasterPolling);
        }
        return $this->fasterPollingClient;
    }

    public function getClientWithoutLogger()
    {
        $client = parent::getClient();
        if (!$this->clientWithoutLogger) {
            $this->clientWithoutLogger = new Client(
                [
                    'token' => $client->token,
                    'url' => $client->getApiUrl(),
                    'userAgent' => $client->getUserAgent(),
                    'backoffMaxTries' => $client->getBackoffMaxTries(),
                    'jobPollRetryDelay' => $this->getStepPollDelayFunction(),
                ]
            );
            if ($client->getRunId()) {
                $this->clientWithoutLogger->setRunId($client->getRunId());
            }
        }
        return $this->clientWithoutLogger;
    }

    public function setFasterPollingClient(Client $client)
    {
        $this->fasterPollingClient = $this->verifyClient($client);
    }
}
