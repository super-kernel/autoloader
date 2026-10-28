<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Exception;

use Throwable;

/**
 * Marks failures raised by the autoloader component.
 */
interface ClassLoaderExceptionInterface extends Throwable
{
}
