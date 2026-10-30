<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader;

use SuperKernel\Autoloader\Exception\ClassLoaderRegistrationException;
use SuperKernel\Autoloader\Exception\InvalidClassMapException;
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;
use SuperKernel\Contract\AutoLoaderInterface;

use function array_any;
use function class_exists;
use function count;
use function enum_exists;
use function get_debug_type;
use function interface_exists;
use function is_array;
use function is_file;
use function is_object;
use function is_string;
use function spl_autoload_functions;
use function spl_autoload_unregister;
use function sprintf;
use function str_contains;
use function trait_exists;

/**
 * Loads construction-time classmap entries as an SPL autoload callback.
 *
 * Symbol checks, pruning, and SPL self-detection use only built-in functions: after takeover this instance may be the
 * only loader, so it could not resolve a helper class outside its classmap or one that its own loading path requires.
 */
final class AutoLoader implements AutoLoaderInterface
{
    /**
     * @var array<class-string, non-empty-string>
     */
    private array $classMap = [];

    /**
     * @var array<positive-int, callable(class-string, non-empty-string): bool>
     */
    private array $interceptors = [];

    /**
     * @var array<class-string, true>
     */
    private array $loading = [];

    /**
     * @var positive-int
     */
    private int $nextInterceptorId = 1;

    /**
     * Initializes the classmap once without changing the SPL autoload stack.
     *
     * @param array<class-string, non-empty-string> $classMap
     *
     * @throws InvalidClassMapException When a class name or file path is invalid
     */
    public function __construct(array $classMap)
    {
        foreach ($classMap as $class => $file) {
            if (!is_string($class) || '' === $class || str_contains($class, "\0")) {
                throw new InvalidClassMapException(sprintf(
                    'Classmap keys must be non-empty strings without null bytes; received %s.',
                    get_debug_type($class),
                ));
            }

            if (!is_string($file) || '' === $file || str_contains($file, "\0")) {
                throw new InvalidClassMapException(sprintf(
                    'The classmap path for "%s" must be a non-empty string without null bytes.',
                    $class,
                ));
            }

            if (
                class_exists($class, false)
                || interface_exists($class, false)
                || trait_exists($class, false)
                || enum_exists($class, false)
            ) {
                continue;
            }

            $this->classMap[$class] = $file;
        }
    }

    public function __invoke(string $class): void
    {
        if (isset($this->loading[$class])) {
            return;
        }

        $this->loading[$class] = true;

        try {
            $file = $this->classMap[$class] ?? null;

            if (
                null === $file
                || class_exists($class, false)
                || interface_exists($class, false)
                || trait_exists($class, false)
                || enum_exists($class, false)
            ) {
                return;
            }

            foreach ($this->interceptors as $interceptor) {
                $intercepted = $interceptor($class, $file);

                if (
                    true === $intercepted
                    || class_exists($class, false)
                    || interface_exists($class, false)
                    || trait_exists($class, false)
                    || enum_exists($class, false)
                ) {
                    return;
                }
            }

            if (is_file($file)) {
                require $file;
            }
        } finally {
            unset($this->loading[$class]);

            foreach ($this->classMap as $mappedClass => $_file) {
                if (
                    class_exists($mappedClass, false)
                    || interface_exists($mappedClass, false)
                    || trait_exists($mappedClass, false)
                    || enum_exists($mappedClass, false)
                ) {
                    unset($this->classMap[$mappedClass]);
                }
            }
        }
    }

    public function getClassMap(): array
    {
        foreach ($this->classMap as $mappedClass => $_file) {
            if (
                class_exists($mappedClass, false)
                || interface_exists($mappedClass, false)
                || trait_exists($mappedClass, false)
                || enum_exists($mappedClass, false)
            ) {
                unset($this->classMap[$mappedClass]);
            }
        }

        return $this->classMap;
    }

    public function addInterceptor(callable $interceptor): int
    {
        $interceptorId = $this->nextInterceptorId++;
        $this->interceptors[$interceptorId] = $interceptor;

        return $interceptorId;
    }

    public function removeInterceptor(int $interceptorId): bool
    {
        if (!isset($this->interceptors[$interceptorId])) {
            return false;
        }

        unset($this->interceptors[$interceptorId]);

        return true;
    }

    public function isRegistered(): bool
    {
        return array_any(
            spl_autoload_functions(),
            fn(mixed $loader): bool => $loader === $this
                || (
                    is_array($loader)
                    && 2 === count($loader)
                    && is_object($loader[0])
                    && $loader[0] === $this
                    && '__invoke' === $loader[1]
                ),
        );
    }

    /**
     * @throws UnloadedClassMapException        When mappings still refer to undeclared symbols
     * @throws ClassLoaderRegistrationException When this loader cannot be removed from SPL
     */
    public function unregister(): void
    {
        foreach ($this->classMap as $mappedClass => $_file) {
            if (
                class_exists($mappedClass, false)
                || interface_exists($mappedClass, false)
                || trait_exists($mappedClass, false)
                || enum_exists($mappedClass, false)
            ) {
                unset($this->classMap[$mappedClass]);
            }
        }

        if ([] !== $this->classMap) {
            throw new UnloadedClassMapException($this->classMap);
        }

        foreach (spl_autoload_functions() as $loader) {
            $isSelfLoader = $loader === $this
                || (
                    is_array($loader)
                    && 2 === count($loader)
                    && is_object($loader[0])
                    && $loader[0] === $this
                    && '__invoke' === $loader[1]
                );

            if (!$isSelfLoader) {
                continue;
            }

            if (!spl_autoload_unregister($loader)) {
                throw new ClassLoaderRegistrationException('Unable to unregister the class loader from SPL.');
            }
        }

        $this->interceptors = [];
        $this->loading = [];
    }
}
