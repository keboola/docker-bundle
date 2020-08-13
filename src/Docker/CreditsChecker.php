<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\StorageApi\Client;
use Psr\Log\NullLogger;

class CreditsChecker
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    private function getBillingServiceUrl()
    {
        $index = $this->client->indexAction();
        foreach ($index['services'] as $service) {
            if ($service['id'] == 'billing') {
                return $service['url'];
            }
        }
        return null;
    }

    public function getBillingClient($url, $token)
    {
        $url = $this->getBillingServiceUrl();
        return new BillingClient(new NullLogger(), $url, $token);
    }

    public function hasCredits()
    {
        $url = $this->getBillingServiceUrl();
        if (!$url) {
            return true; // billing service not available, run everything
        }
        $tokenInfo = $this->client->verifyToken();
        if (!in_array('pay-as-you-go', $tokenInfo['owner']['features'])) {
            return true; // not a payg project, run everything
        }
        return $this->getBillingClient($url, $this->client->token)->getRemainingCredits() > 0;
    }
}
