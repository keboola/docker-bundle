<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\NoRequestException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Exception;
use Symfony\Component\HttpFoundation\RequestStack;

class StorageApiService extends \Keboola\Syrup\Service\StorageApi\StorageApiService
{
    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $storageApiUrl;

    /**
     * @var array
     */
    protected $tokenData;

    public function __construct(RequestStack $requestStack, $storageApiUrl)
    {
        $this->storageApiUrl = $storageApiUrl;
        $this->requestStack = $requestStack;
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

    /**
     * @return Client
     */
    public function getClient()
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($this->client == null) {
            if ($request == null) {
                throw new NoRequestException('');
            }

            if (!$request->headers->has('X-StorageApi-Token')) {
                throw new Exception('Missing StorageAPI token');
            }

            $this->setClient(
                new Client(
                    [
                        'token' => $request->headers->get('X-StorageApi-Token'),
                        'url' => $this->storageApiUrl,
                        'userAgent' => explode('/', $request->getPathInfo())[1],
                        'backoffMaxTries' => $this->getBackoffTries(gethostname()),
                        'jobPollRetryDelay' => self::getStepPollDelayFunction()
                    ]
                )
            );

            if ($request->headers->has('X-KBC-RunId')) {
                $this->client->setRunId($request->headers->get('X-KBC-RunId'));
            }
        }
        return $this->client;
    }
}
