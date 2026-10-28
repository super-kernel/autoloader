<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader;

use SuperKernel\Autoloader\Exception\ClassLoaderRegistrationException;
use SuperKernel\Autoloader\Exception\InvalidClassMapException;
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;
use SuperKernel\Contract\AutoLoaderInterface;

use function array_any;
use function array_values;
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

            if (self::isSymbolLoaded($class)) {
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
            $this->pruneLoadedClassMap();

            if (self::isSymbolLoaded($class)) {
                return;
            }

            $file = $this->classMap[$class] ?? null;

            if (null === $file) {
                return;
            }

            foreach ($this->interceptors as $interceptor) {
                $intercepted = $interceptor($class, $file);
                $this->pruneLoadedClassMap();

                if (true === $intercepted || self::isSymbolLoaded($class)) {
                    return;
                }
            }

            if (is_file($file)) {
                require $file;
                $this->pruneLoadedClassMap();

                if (self::isSymbolLoaded($class)) {
                    return;
                }
            }
        } finally {
            unset($this->loading[$class]);
            $this->pruneLoadedClassMap();
        }
    }

    public function getClassMap(): array
    {
        $this->pruneLoadedClassMap();

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
        return array_any(self::splStack(), fn($loader) => $this->isSelfLoader($loader));
    }

    /**
     * @throws UnloadedClassMapException        When mappings still refer to undeclared symbols
     * @throws ClassLoaderRegistrationException When this loader cannot be removed from SPL
     */
    public function unregister(): void
    {
        $this->pruneLoadedClassMap();

        if ([] !== $this->classMap) {
            throw new UnloadedClassMapException($this->classMap);
        }

        foreach (self::splStack() as $loader) {
            if (!$this->isSelfLoader($loader)) {
                continue;
            }

            if (!spl_autoload_unregister($loader)) {
                throw new ClassLoaderRegistrationException('Unable to unregister the class loader from SPL.');
            }
        }

        $this->interceptors = [];
        $this->loading = [];
    }

    private function pruneLoadedClassMap(): void
    {
        foreach ($this->classMap as $class => $_file) {
            if (self::isSymbolLoaded($class)) {
                unset($this->classMap[$class]);
            }
        }
    }

    private static function isSymbolLoaded(string $class): bool
    {
        return class_exists($class, false)
            || interface_exists($class, false)
            || trait_exists($class, false)
            || enum_exists($class, false);
    }

    /**
     * @return list<callable>
     */
    private static function splStack(): array
    {
        return array_values(spl_autoload_functions() ?: []);
    }

    private function isSelfLoader(mixed $loader): bool
    {
        return $loader === $this
            || (
                is_array($loader)
                && 2 === count($loader)
                && is_object($loader[0])
                && $loader[0] === $this
                && '__invoke' === $loader[1]
            );
    }
}
