<?php

namespace Keboola\DockerBundle\Monolog\Handler;

use Keboola\DockerBundle\Exception\NoRequestException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Keboola\StorageApi\Exception;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\Container;

/**
 * Class StorageApiHandler
 * @package Keboola\DockerBundle\Monolog\Handler
 */
class StorageApiHandler extends \Monolog\Handler\AbstractHandler implements StorageApiHandlerInterface
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
     * @var \Keboola\StorageApi\Client
     */
    protected $storageApiClient;

    /**
     * @var Container
     */
    private $container;

    /**
     * StorageApiHandler constructor.
     * @param string $appName
     * @param Container $container
     */
    public function __construct($appName, Container $container)
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
        $this->container = $container;
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

    protected function initStorageApiClient()
    {
        try {
            $this->storageApiClient = $this->container->get('syrup.storage_api')->getClientWithoutLogger();
        } catch (\InvalidArgumentException $e) {
            // Ignore when SAPI client is not initialized properly (yet).
        } catch (\Keboola\Syrup\Exception\NoRequestException $e) {
            // Ignore when no SAPI client setup
        } catch (NoRequestException $e) {
            // Ignore when no SAPI client setup
        } catch (UserException $e) {
            // Ignore when no SAPI client setup
        } catch (\Keboola\Syrup\Exception\UserException $e) {
            // Ignore when no SAPI client setup
        } catch (\Keboola\ObjectEncryptor\Exception\UserException $e) {
            // Ignore when no SAPI client setup
        } catch (Exception $e) {
            // Ignore when SAPI client setup is wrong
        }
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
            $this->initStorageApiClient();
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
