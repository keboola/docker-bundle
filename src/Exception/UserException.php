<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;

class UserException extends ApplicationException implements UserExceptionInterface
{
}
