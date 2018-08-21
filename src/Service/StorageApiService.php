<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class StorageApiService
{
    /**
     * @var Client
     */
    private $fasterPollingClient = null;

    /** @var RequestStack */
    protected $requestStack;

    /** @var Request */
    protected $request;
    /**
     * @var LoggerInterface
     */
    private $logger;


    /** @var Client */
    protected $client;

    protected $storageApiUrl;

    protected $tokenData;

    public function __construct(RequestStack $requestStack, $storageApiUrl = 'https://connection.keboola.com')
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
    public static function getStepPollDelayFunction()
    {
        $this->storageApiUrl = $storageApiUrl;
        $this->requestStack = $requestStack;
    }

    protected function verifyClient(Client $client)
    {
        try {
            $this->tokenData = $client->verifyToken();
            return $client;
        } catch (ClientException $e) {
            if ($e->getCode() == 401) {
                throw new UserException("Invalid StorageApi Token", $e);
            } elseif ($e->getCode() < 500) {
                throw new UserException($e->getMessage(), $e);
            }
            throw $e;
        }
    }

    public function getBackoffTries($hostname)
    {
        // keep the backoff settings minimal for API servers
        if (false === strstr($hostname, 'worker')) {
            return 3;
        }

        return 11;
    }

    public function setClient(Client $client)
    {
        $this->client = $this->verifyClient($client);
    }

    public function getClient()
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($this->client == null) {
            if ($request == null) {
                throw new UserException('no request');
            }

            if (!$request->headers->has('X-StorageApi-Token')) {
                throw new UserException('Missing StorageAPI token');
            }

            $this->setClient(
                new Client(
                    [
                        'token' => $request->headers->get('X-StorageApi-Token'),
                        'url' => $this->storageApiUrl,
                        'userAgent' => explode('/', $request->getPathInfo())[1],
                        'backoffMaxTries' => $this->getBackoffTries(gethostname())
                    ]
                )
            );

            if ($request->headers->has('X-KBC-RunId')) {
                $this->client->setRunId($request->headers->get('X-KBC-RunId'));
            }
        }

        $client = $this->client;
        if (!$this->fasterPollingClient) {
            $clientWithFasterPolling = new Client(
                [
                    'token' => $client->token,
                    'url' => $client->getApiUrl(),
                    'userAgent' => $client->getUserAgent(),
                    'backoffMaxTries' => $client->getBackoffMaxTries(),
                    'jobPollRetryDelay' => self::getStepPollDelayFunction(),
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


    public function getTokenData()
    {
        if ($this->tokenData == null) {
            throw new ApplicationException('StorageApi Client was not initialized');
        }
        return $this->tokenData;
    }

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
                    'jobPollRetryDelay' => self::getStepPollDelayFunction(),
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
