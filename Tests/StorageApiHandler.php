<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;

use function Keboola\Utils\sanitizeUtf8;

class StorageApiHandler extends AbstractHandler implements StorageApiHandlerInterface
{
    private array $verbosity;
    protected string $appName;
    protected Client $storageApiClient;

    public function __construct(string $appName, Client $client)
    {
        parent::__construct();
        $this->appName = $appName;
        $this->verbosity[Level::Debug->value] = self::VERBOSITY_NONE;
        $this->verbosity[Level::Info->value] = self::VERBOSITY_NORMAL;
        $this->verbosity[Level::Notice->value] = self::VERBOSITY_NORMAL;
        $this->verbosity[Level::Warning->value] = self::VERBOSITY_NORMAL;
        $this->verbosity[Level::Error->value] = self::VERBOSITY_NORMAL;
        $this->verbosity[Level::Critical->value] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Level::Alert->value] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Level::Emergency->value] = self::VERBOSITY_CAMOUFLAGE;
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

    public function handle(LogRecord $record): bool
    {
        if (($this->verbosity[$record->level->value] === self::VERBOSITY_NONE) || empty($record->message)) {
            return false;
        }

        $event = new Event();
        if (!empty($record->component)) {
            $event->setComponent($record->component);
        } else {
            $event->setComponent($this->appName);
        }
        $event->setMessage(sanitizeUtf8($record->message));
        $event->setRunId($this->storageApiClient->getRunId());
        $event->setParams([]);

        if ($this->verbosity[$record->level->value] === self::VERBOSITY_VERBOSE) {
            $results = $record->context;
        } else {
            $results = [];
        }
        $event->setResults($results);

        if ($this->verbosity[$record->level->value] === self::VERBOSITY_CAMOUFLAGE) {
            $event->setMessage('Application error');
            $event->setDescription('Contact support@keboola.com');
        }

        switch ($record->level) {
            case Level::Error:
            case Level::Critical:
            case Level::Emergency:
            case Level::Alert:
                $type = Event::TYPE_ERROR;
                break;
            case Level::Warning:
            case Level::Notice:
                $type = Event::TYPE_WARN;
                break;
            case Level::Info:
            default:
                $type = Event::TYPE_INFO;
                break;
        }
        $event->setType($type);

        $this->storageApiClient->createEvent($event);
        return false;
    }
}
