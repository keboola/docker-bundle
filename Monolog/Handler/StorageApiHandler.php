<?php

namespace Keboola\DockerBundle\Monolog\Handler;

use Keboola\StorageApi\Event;
use Monolog\Logger;

class StorageApiHandler extends \Keboola\Syrup\Monolog\Handler\StorageApiHandler
{
    public function handle(array $record)
    {
        $this->initStorageApiClient();

        if (!$this->storageApiClient || $record['level'] == Logger::DEBUG) {
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

        $params = [];
        if (isset($record['http'])) {
            $params['http'] = $record['http'];
        }
        $event->setParams($params);

        $results = [];
        if (isset($record['context']['exceptionId'])) {
            $results['exceptionId'] = $record['context']['exceptionId'];
        }
        if (isset($record['context']['job'])) {
            $results['job'] = $record['context']['job'];
        }
        $event->setResults($results);

        switch ($record['level']) {
            case Logger::ERROR:
                $type = Event::TYPE_ERROR;
                break;
            case Logger::CRITICAL:
            case Logger::EMERGENCY:
            case Logger::ALERT:
                $type = Event::TYPE_ERROR;
                $event->setMessage("Application error");
                $event->setDescription("Contact support@keboola.com");
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
