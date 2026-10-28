<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Exception;

use RuntimeException;

final class ClassLoaderRegistrationException extends RuntimeException implements ClassLoaderExceptionInterface
{
}
