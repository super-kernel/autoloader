<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Exception;

use InvalidArgumentException;
use RuntimeException;

use function array_key_first;
use function is_string;
use function sprintf;

final class UnloadedClassMapException extends RuntimeException implements ClassLoaderExceptionInterface
{
    /**
     * @var non-empty-array<class-string, non-empty-string>
     */
    private readonly array $unloadedClassMap;

    /**
     * @var class-string
     */
    private readonly string $firstClassName;

    /**
     * @param non-empty-array<class-string, non-empty-string> $unloadedClassMap Pending mappings in insertion order
     */
    public function __construct(array $unloadedClassMap)
    {
        $firstClassName = array_key_first($unloadedClassMap);

        if (!is_string($firstClassName)) {
            throw new InvalidArgumentException('An unloaded classmap must not be empty.');
        }

        $this->unloadedClassMap = $unloadedClassMap;
        $this->firstClassName = $firstClassName;

        parent::__construct(sprintf('Class "%s" has not been loaded.', $firstClassName));
    }

    /**
     * @return non-empty-array<class-string, non-empty-string>
     */
    public function classMap(): array
    {
        return $this->unloadedClassMap;
    }

    /**
     * @return class-string
     */
    public function firstClassName(): string
    {
        return $this->firstClassName;
    }
}
