<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Provider;

use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Autoloader\AutoLoader;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Contract\PackageManagerInterface;
use Throwable;

use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;

#[FactoryProvider(AutoLoaderInterface::class)]
final readonly class AutoLoaderProvider
{
    /**
     * Constructs a fresh loader before replacing every current SPL autoload entry.
     * Package order determines class ownership, with Root first.
     *
     * @throws Throwable When bootstrap loading or construction fails; the original SPL stack is restored
     */
    public function __invoke(PackageManagerInterface $packages): AutoLoaderInterface
    {
        $classMap = [];

        foreach ($packages->all() as $package) {
            $classMap += $packages->classes($package->name()) ?? [];
        }

        $bootstrap = static function (string $class) use ($classMap): void {
            if (isset($classMap[$class])) {
                require $classMap[$class];
            }
        };
        $previousStack = spl_autoload_functions();
        spl_autoload_register($bootstrap, true, true);

        try {
            $loader = new AutoLoader($classMap);
            self::replaceSplStack([
                $loader,
            ]);
        } catch (Throwable $failure) {
            self::replaceSplStack($previousStack);

            throw $failure;
        }

        return $loader;
    }

    /** @param list<callable> $loaders */
    private static function replaceSplStack(array $loaders): void
    {
        foreach (spl_autoload_functions() as $registered) {
            spl_autoload_unregister($registered);
        }

        foreach ($loaders as $loader) {
            spl_autoload_register($loader);
        }
    }
}
