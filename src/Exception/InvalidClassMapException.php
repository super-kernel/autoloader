<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Exception;

use InvalidArgumentException;

final class InvalidClassMapException extends InvalidArgumentException implements ClassLoaderExceptionInterface
{
}
