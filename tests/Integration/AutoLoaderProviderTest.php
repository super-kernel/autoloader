<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Tests\Integration;

use Closure;
use Error;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Autoloader\AutoLoader;
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;
use SuperKernel\Autoloader\Provider\AutoLoaderProvider;
use SuperKernel\Autoloader\Tests\Fixture\CancelledInterceptorClass;
use SuperKernel\Autoloader\Tests\Fixture\ReplacementClass;
use SuperKernel\Composer\Enum\PackageType;
use SuperKernel\Composer\Manager\PackageManager;
use SuperKernel\Composer\Model\Package;
use SuperKernel\Composer\Model\ScanRuleSet;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Contract\PackageManagerInterface;
use Throwable;

use function class_exists;
use function dirname;
use function file_put_contents;
use function interface_exists;
use function is_file;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AutoLoaderProviderTest extends TestCase
{
    public function testProviderDeclaresTheLoaderInterfaceBinding(): void
    {
        $reflection = new ReflectionClass(AutoLoaderProvider::class);
        $attributes = $reflection->getAttributes(FactoryProvider::class);

        $this->assertCount(1, $attributes);
        $this->assertSame(AutoLoaderInterface::class, $attributes[0]->newInstance()->className);
    }

    /** @throws Throwable When class-loader bootstrap or a mapped declaration fails */
    public function testColdBootstrapIncludesRootAndDependenciesBeforeTakingOverTheStack(): void
    {
        $provider = new AutoLoaderProvider();
        $bridge = $this->temporaryFile();
        $originalStack = spl_autoload_functions();
        $bootstrapStack = [];
        $previousCalls = [];
        $previous = static function (string $class) use (&$previousCalls): void {
            $previousCalls[] = $class;
        };
        $GLOBALS['super_kernel_autoloader_bootstrap_probe'] = static function () use (&$bootstrapStack): void {
            $bootstrapStack = spl_autoload_functions();
        };

        try {
            $written = file_put_contents(
                $bridge,
                "<?php\n(\$GLOBALS['super_kernel_autoloader_bootstrap_probe'])();\nrequire "
                . var_export(dirname(__DIR__, 2) . '/src/AutoLoader.php', true) . ";\n",
            );
            $this->assertNotFalse($written);
            $packageManagerFile = new ReflectionClass(PackageManagerInterface::class)->getFileName();
            $this->assertIsString($packageManagerFile);
            $interfaceFile = dirname($packageManagerFile) . '/AutoLoaderInterface.php';
            $exceptionInterfaceFile = dirname(__DIR__, 2) . '/src/Exception/ClassLoaderExceptionInterface.php';
            $unloadedMapFile = dirname(__DIR__, 2) . '/src/Exception/UnloadedClassMapException.php';
            $cancelledFile = $this->fixture('CancelledInterceptorClass.php');
            $packages = $this->packages([
                'fixture/application' => [
                    SuperKernel\Autoloader\AutoLoader::class                     => $bridge,
                    SuperKernel\Autoloader\Tests\Fixture\ReplacementClass::class => $this->fixture(
                        'ReplacementClass.php',
                    ),
                ],
                'fixture/dependency' => [
                    SuperKernel\Contract\AutoLoaderInterface::class                       => $interfaceFile,
                    SuperKernel\Autoloader\Exception\ClassLoaderExceptionInterface::class => $exceptionInterfaceFile,
                    SuperKernel\Autoloader\Exception\UnloadedClassMapException::class     => $unloadedMapFile,
                    SuperKernel\Autoloader\Tests\Fixture\CancelledInterceptorClass::class => $cancelledFile,
                    SuperKernel\Autoloader\Tests\Fixture\ReplacementClass::class          => '/missing/shadowed.php',
                ],
            ]);
            $this->assertFalse(class_exists(AutoLoader::class, false));
            $this->assertFalse(interface_exists(AutoLoaderInterface::class, false));
            $this->assertFalse(class_exists(UnloadedClassMapException::class, false));
            self::replaceStack([
                $previous,
            ]);

            $loader = $provider($packages);
            $stack = spl_autoload_functions();
            $firstPendingClass = null;

            try {
                $loader->unregister();
            } catch (UnloadedClassMapException $exception) {
                $firstPendingClass = $exception->firstClassName();
            }

            $rootLoaded = class_exists(ReplacementClass::class);
            $dependencyLoaded = class_exists(CancelledInterceptorClass::class);
            $pending = $loader->getClassMap();
            $loader->unregister();
            $afterRemoval = spl_autoload_functions();
        } finally {
            self::replaceStack($originalStack);
            unset($GLOBALS['super_kernel_autoloader_bootstrap_probe']);

            if (is_file($bridge)) {
                unlink($bridge);
            }
        }

        $this->assertCount(2, $bootstrapStack);
        $this->assertInstanceOf(Closure::class, $bootstrapStack[0]);
        $this->assertSame($previous, $bootstrapStack[1]);
        $this->assertSame([
            $loader,
        ], $stack);
        $this->assertSame(ReplacementClass::class, $firstPendingClass);
        $this->assertTrue($rootLoaded);
        $this->assertTrue($dependencyLoaded);
        $this->assertSame([], $pending);
        $this->assertSame([], $previousCalls);
        $this->assertSame([], $afterRemoval);
    }

    /** @throws Throwable When an unexpected bootstrap failure occurs */
    public function testNativeBootstrapErrorsPropagateAndRestoreTheOriginalStack(): void
    {
        $provider = new AutoLoaderProvider();
        $file = $this->temporaryFile();
        $originalStack = spl_autoload_functions();
        $failure = null;

        try {
            $written = file_put_contents($file, "<?php\nthrow new Error('bootstrap failure');\n");
            $this->assertNotFalse($written);
            $packages = $this->packages([
                'fixture/application' => [
                    SuperKernel\Autoloader\AutoLoader::class => $file,
                ],
            ]);
            $this->assertFalse(class_exists(AutoLoader::class, false));

            try {
                $provider($packages);
            } catch (Error $exception) {
                $failure = $exception;
            }

            $afterFailure = spl_autoload_functions();
        } finally {
            self::replaceStack($originalStack);

            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->assertInstanceOf(Error::class, $failure);
        $this->assertSame('bootstrap failure', $failure->getMessage());
        $this->assertSame($originalStack, $afterFailure);
        $this->assertFalse(class_exists(AutoLoader::class, false));
    }

    /** @param array<string, array<class-string, non-empty-string>> $classMaps */
    private function packages(array $classMaps): PackageManager
    {
        $root = new Package(
            'fixture/application',
            null,
            null,
            PackageType::Root,
            false,
            dirname(__DIR__, 2),
            ScanRuleSet::empty(),
        );
        $packages = [];

        foreach ($classMaps as $name => $_classes) {
            if ('fixture/application' === $name) {
                continue;
            }

            $packages[] = new Package(
                $name,
                '1.0.0',
                'fixture-reference',
                PackageType::Library,
                false,
                dirname(__DIR__, 2),
                ScanRuleSet::empty(),
            );
        }

        return new PackageManager($root, $packages, $classMaps);
    }

    /** @return non-empty-string */
    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'super-kernel-loader-provider-');

        if (false === $file) {
            $this->fail('Unable to create the bootstrap fixture.');
        }

        return $file;
    }

    /** @return non-empty-string */
    private function fixture(string $file): string
    {
        return dirname(__DIR__) . '/Fixture/' . $file;
    }

    /** @param list<callable> $loaders */
    private static function replaceStack(array $loaders): void
    {
        foreach (spl_autoload_functions() as $loader) {
            spl_autoload_unregister($loader);
        }

        foreach ($loaders as $loader) {
            spl_autoload_register($loader);
        }
    }
}
