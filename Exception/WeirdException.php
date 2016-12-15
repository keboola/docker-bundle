<?php

namespace Keboola\DockerBundle\Exception;

class WeirdException extends \Exception
{
    const ERROR_DEV_MAPPER = 'Error response from daemon: open /dev/mapper/';
    const ERROR_DEVICE_RESUME = 'Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed';
}
