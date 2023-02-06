<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Monolog\Handler;

use Monolog\Handler\HandlerInterface;
use Monolog\ResettableInterface;

interface StorageApiHandlerInterface extends HandlerInterface, ResettableInterface
{
    /**
     * Verbosity None - event will not be stored in Storage at all.
     */
    public const VERBOSITY_NONE = 'none';

    /**
     * Verbosity Camouflage - event will be stored in Storage only as a generic message.
     */
    public const VERBOSITY_CAMOUFLAGE = 'camouflage';

    /**
     * Verbosity Normal - event will be stored in Storage as received.
     */
    public const VERBOSITY_NORMAL = 'normal';

    /**
     * Verbosity Verbose - event will be stored in Storage including all additonal event data.
     */
    public const VERBOSITY_VERBOSE = 'verbose';

    /**
     * Set verbosity for each error level. If a level is not provided, its verbosity will not be changed.
     * @param array $verbosity Key is Monolog error level, value is verbosity constant.
     */
    public function setVerbosity(array $verbosity);

    /**
     * Get verbosity for each error level.
     * @return array Key is Monolog error level, value is verbosity constant.
     */
    public function getVerbosity();
}
