<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;

/**
 * Class StorageApiHandler
 * @package Keboola\DockerBundle\Monolog\Handler
 */
class StorageApiHandler extends AbstractHandler implements StorageApiHandlerInterface
{
    /**
     * @var array
     */
    private $verbosity;

    /**
     * @var string
     */
    protected $appName;

    /**
     * @var Client
     */
    protected $storageApiClient;

    /**
     * StorageApiHandler constructor.
     * @param string $appName
     * @param Client $client
     */
    public function __construct($appName, Client $client)
    {
        parent::__construct();
        $this->appName = $appName;
        $this->verbosity[Logger::DEBUG] = self::VERBOSITY_NONE;
        $this->verbosity[Logger::INFO] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::NOTICE] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::WARNING] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::ERROR] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::CRITICAL] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Logger::ALERT] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Logger::EMERGENCY] = self::VERBOSITY_CAMOUFLAGE;
        $this->storageApiClient = $client;
    }

    /**
     * @inheritDoc
     */
    public function setVerbosity(array $verbosity)
    {
        foreach ($verbosity as $level => $value) {
            $this->verbosity[$level] = $value;
        }
    }

    /**
     * @inheritDoc
     */
    public function getVerbosity()
    {
        return $this->verbosity;
    }

    /**
     * @inheritdoc
     */
    public function handle(array $record)
    {
        if (($this->verbosity[$record['level']] == self::VERBOSITY_NONE) || empty($record['message'])) {
            return false;
        }

        if (!$this->storageApiClient) {
            return false;
        }

        $event = new Event();
        if (!empty($record['component'])) {
            $event->setComponent($record['component']);
        } else {
            $event->setComponent($this->appName);
        }
        $event->setMessage(\Keboola\Utils\sanitizeUtf8($record['message']));
        $event->setRunId($this->storageApiClient->getRunId());
        $event->setParams([]);

        if ($this->verbosity[$record['level']] == self::VERBOSITY_VERBOSE) {
            $results = $record['context'];
        } else {
            $results = [];
        }
        $event->setResults($results);

        if ($this->verbosity[$record['level']] == self::VERBOSITY_CAMOUFLAGE) {
            $event->setMessage("Application error");
            $event->setDescription("Contact support@keboola.com");
        }

        switch ($record['level']) {
            case Logger::ERROR:
                $type = Event::TYPE_ERROR;
                break;
            case Logger::CRITICAL:
            case Logger::EMERGENCY:
            case Logger::ALERT:
                $type = Event::TYPE_ERROR;
                break;
            case Logger::WARNING:
            case Logger::NOTICE:
                $type = Event::TYPE_WARN;
                break;
            case Logger::INFO:
            default:
                $type = Event::TYPE_INFO;
                break;
        }
        $event->setType($type);

        $this->storageApiClient->createEvent($event);
        return false;
    }
}
