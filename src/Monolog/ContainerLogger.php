<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Monolog;

use DateTime;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ContainerLogger extends Logger
{
    /**
     * Translates Syslog log priorities to Monolog log levels.
     */
    protected array $logLevels = [
        7 => Logger::DEBUG,
        6 => Logger::INFO,
        5 => Logger::NOTICE,
        4 => Logger::WARNING,
        3 => Logger::ERROR,
        2 => Logger::CRITICAL,
        1 => Logger::ALERT,
        0 => Logger::EMERGENCY,
    ];


    protected function syslogToMonologLevel(int $level): int
    {
        return $this->logLevels[$level] ?? Logger::ERROR;
    }

    /**
     * Adds a log record.
     *
     * @param int $level The logging level
     * @param string $message The log message
     * @param array $context The log context
     * @return bool Whether the record has been processed
     */
    public function addRawRecord(int $level, int $timestamp, string $message, array $context = []): bool
    {
        if (!$this->handlers) {
            $this->pushHandler(new StreamHandler('php://stderr', static::DEBUG));
        }

        $level = $this->syslogToMonologLevel($level);
        $levelName = static::getLevelName($level);

        // check if any handler will handle this message, so we can return early and save cycles
        $handlerKey = null;
        reset($this->handlers);
        while ($handler = current($this->handlers)) {
            if ($handler->isHandling(['level' => $level])) {
                $handlerKey = key($this->handlers);
                break;
            }

            next($this->handlers);
        }

        if ($handlerKey === null) {
            return false;
        }

        if ($this->microsecondTimestamps) {
            $ts = DateTime::createFromFormat('U.u', sprintf('%.6F', $timestamp), $this->getTimezone());
        } else {
            $ts = DateTime::createFromFormat('U', sprintf('%.0F', $timestamp), $this->getTimezone());
        }
        $ts->setTimezone($this->getTimezone());

        $record = [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => $levelName,
            'channel' => $this->name,
            'datetime' => $ts,
            'extra' => [],
        ];

        foreach ($this->processors as $processor) {
            $record = call_user_func($processor, $record);
        }

        while ($handler = current($this->handlers)) {
            if ($handler->handle($record) === true) {
                break;
            }

            next($this->handlers);
        }

        return true;
    }
}
