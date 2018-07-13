<?php

namespace Keboola\DockerBundle\Monolog;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ContainerLogger extends Logger
{
    /**
     * Translates Syslog log priorities to Monolog log levels.
     */
    protected $logLevels = [
        7 => Logger::DEBUG,
        6 => Logger::INFO,
        5 => Logger::NOTICE,
        4 => Logger::WARNING,
        3 => Logger::ERROR,
        2 => Logger::CRITICAL,
        1 => Logger::ALERT,
        0 => Logger::EMERGENCY,
    ];


    protected function syslogToMonologLevel($level)
    {
        if (isset($this->logLevels[$level])) {
            return $this->logLevels[$level];
        } else {
            return Logger::ERROR;
        }
    }

    /**
     * Adds a log record.
     *
     * @param  int $level The logging level
     * @param $timestamp
     * @param  string $message The log message
     * @param  array $context The log context
     * @return bool Whether the record has been processed
     */
    public function addRawRecord($level, $timestamp, $message, array $context = array())
    {
        if (!$this->handlers) {
            $this->pushHandler(new StreamHandler('php://stderr', static::DEBUG));
        }

        $level = $this->syslogToMonologLevel($level);
        $levelName = static::getLevelName($level);

        // check if any handler will handle this message so we can return early and save cycles
        $handlerKey = null;
        reset($this->handlers);
        while ($handler = current($this->handlers)) {
            if ($handler->isHandling(array('level' => $level))) {
                $handlerKey = key($this->handlers);
                break;
            }

            next($this->handlers);
        }

        if (null === $handlerKey) {
            return false;
        }

        if (!static::$timezone) {
            static::$timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
        }

        if ($this->microsecondTimestamps) {
            $ts = \DateTime::createFromFormat('U.u', sprintf('%.6F', $timestamp), static::$timezone);
        } else {
            $ts = \DateTime::createFromFormat('U', sprintf('%.0F', $timestamp), static::$timezone);
        }
        $ts->setTimezone(static::$timezone);

        $record = array(
            'message' => (string) $message,
            'context' => $context,
            'level' => $level,
            'level_name' => $levelName,
            'channel' => $this->name,
            'datetime' => $ts,
            'extra' => array(),
        );

        foreach ($this->processors as $processor) {
            $record = call_user_func($processor, $record);
        }

        while ($handler = current($this->handlers)) {
            if (true === $handler->handle($record)) {
                break;
            }

            next($this->handlers);
        }

        return true;
    }
}
