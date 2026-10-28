<?php

declare(strict_types=1);

namespace SuperKernel\Autoloader\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestRunner\ChildProcessOutputCollector;
use PHPUnit\TestRunner\TestResult\PassedTests;
use SuperKernel;
use SuperKernel\Autoloader\AutoLoader;
use SuperKernel\Autoloader\Exception\ClassLoaderRegistrationException;
use SuperKernel\Autoloader\Exception\InvalidClassMapException;
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;
use SuperKernel\Autoloader\Provider\AutoLoaderProvider;
use SuperKernel\Autoloader\Tests\Fixture\CancelledInterceptorClass;
use SuperKernel\Autoloader\Tests\Fixture\DelegatedClass;
use SuperKernel\Autoloader\Tests\Fixture\FirstMappedClass;
use SuperKernel\Autoloader\Tests\Fixture\InterceptedClass;
use SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureEnum;
use SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureInterface;
use SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureTrait;
use SuperKernel\Autoloader\Tests\Fixture\ReplacementClass;
use SuperKernel\Autoloader\Tests\Fixture\SecondMappedClass;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\PackageMetadataInterface;
use Throwable;

use function class_exists;
use function dirname;
use function enum_exists;
use function file_put_contents;
use function interface_exists;
use function is_file;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function sys_get_temp_dir;
use function tempnam;
use function trait_exists;
use function unlink;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AutoLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(ChildProcessOutputCollector::class);
        class_exists(PassedTests::class);
        class_exists(ClassLoaderRegistrationException::class);
        class_exists(InvalidClassMapException::class);
        class_exists(UnloadedClassMapException::class);
    }

    public function testConstructionAndInterceptorsDoNotChangeTheSplStackOrShareState(): void
    {
        $originalStack = spl_autoload_functions();
        $first = new AutoLoader([
            SuperKernel\Autoloader\Tests\Fixture\ReplacementClass::class => $this->fixture('ReplacementClass.php'),
        ]);
        $second = new AutoLoader([]);
        $first->addInterceptor(static fn(string $_class, string $_file): bool => false);

        $this->assertNotSame($first, $second);
        $this->assertSame([], $second->getClassMap());
        $this->assertSame($originalStack, spl_autoload_functions());
        $this->assertFalse($first->isRegistered());
        $this->assertFalse($second->isRegistered());
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testFiltersEveryKindOfAlreadyLoadedClassLikeSymbol(): void
    {
        $this->assertTrue(class_exists(self::class, false));
        $this->assertTrue(interface_exists(LoadedFixtureInterface::class));
        $this->assertTrue(trait_exists(LoadedFixtureTrait::class));
        $this->assertTrue(enum_exists(LoadedFixtureEnum::class));

        $loader = $this->register([
            self::class                                                        => __FILE__,
            SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureInterface::class => __FILE__,
            SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureTrait::class     => __FILE__,
            SuperKernel\Autoloader\Tests\Fixture\LoadedFixtureEnum::class      => __FILE__,
        ]);

        $this->assertSame([], $loader->getClassMap());
        $loader->unregister();
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testRejectsAnInvalidMapAtomicallyBeforeChangingTheSplStack(): void
    {
        $originalStack = spl_autoload_functions() ?: [];

        try {
            $this->register([
                SuperKernel\Autoloader\Tests\Fixture\ReplacementClass::class => $this->fixture('ReplacementClass.php'),
                0                                                            => $this->fixture('ReplacementClass.php'),
            ]);
            $this->fail('An invalid classmap key must be rejected.');
        } catch (InvalidClassMapException $exception) {
            $this->assertStringContainsString('Classmap keys', $exception->getMessage());
        }

        $this->assertSame($originalStack, spl_autoload_functions());
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When class-loader bootstrap unexpectedly fails
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testTakesSoleOwnershipAndPermanentlyDiscardsExistingLoaders(): void
    {
        $calls = 0;
        $sentinel = static function (string $_class) use (&$calls): void {
            ++$calls;
        };
        spl_autoload_register($sentinel);

        $loader = $this->register();

        $this->assertSame([
            $loader,
        ], spl_autoload_functions());
        $this->assertFalse(class_exists(DelegatedClass::class));
        $this->assertSame(0, $calls);

        $loader->unregister();

        $this->assertSame([], spl_autoload_functions() ?: []);
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When class-loader bootstrap unexpectedly fails
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testRegistrationPermanentlyDiscardsLoadersAddedAfterInitialTakeover(): void
    {
        $loader = $this->register();
        $calls = 0;
        $lateLoader = static function (string $_class) use (&$calls): void {
            ++$calls;
        };
        spl_autoload_register($lateLoader);
        $loader->addInterceptor(static fn(string $_class, string $_file): bool => false);

        $this->assertSame([
            $loader,
            $lateLoader,
        ], spl_autoload_functions());
        $previous = $loader;
        $loader = $this->register();
        $this->assertNotSame($previous, $loader);
        $this->assertFalse($previous->isRegistered());
        $this->assertSame([
            $loader,
        ], spl_autoload_functions());
        $this->assertFalse(class_exists(DelegatedClass::class));
        $this->assertSame(0, $calls);

        $loader->unregister();
        $this->assertSame([], spl_autoload_functions() ?: []);
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When class-loader bootstrap unexpectedly fails
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testUnregisterOnlyRemovesItselfAndPreservesLaterLoaders(): void
    {
        $loader = $this->register();
        $calls = 0;
        $laterLoader = static function (string $_class) use (&$calls): void {
            ++$calls;
        };
        spl_autoload_register($laterLoader, true, true);

        $this->assertSame([
            $laterLoader,
            $loader,
        ], spl_autoload_functions());

        $loader->unregister();

        $this->assertSame([
            $laterLoader,
        ], spl_autoload_functions());
        $this->assertFalse($loader->isRegistered());
        $this->assertFalse(class_exists('Acme\\Unmapped\\LaterClass'));
        $this->assertSame(1, $calls);

        $this->assertTrue(spl_autoload_unregister($laterLoader));
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testInternalExceptionsRemainAvailableAfterStrictTakeover(): void
    {
        $classMap = [
            'Acme\\Pending\\AfterTakeover' => '/missing/pending.php',
        ];
        $loader = $this->register($classMap);

        try {
            new AutoLoader([
                0 => '/missing/invalid.php',
            ]);
            $this->fail('Invalid construction must still throw after the original loaders are removed.');
        } catch (InvalidClassMapException $exception) {
            $this->assertStringContainsString('Classmap keys', $exception->getMessage());
        }

        try {
            $loader->unregister();
            $this->fail('Pending mappings must still throw after the original loaders are removed.');
        } catch (UnloadedClassMapException $exception) {
            $this->assertSame($classMap, $exception->classMap());
        }

        $this->assertSame([
            $loader,
        ], spl_autoload_functions());
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testClassmapCannotBeChangedThroughInputOrReturnedArrays(): void
    {
        $classMap = [
            SuperKernel\Autoloader\Tests\Fixture\ReplacementClass::class => $this->fixture('ReplacementClass.php'),
        ];
        $loader = $this->register($classMap);
        $original = $classMap;
        $classMap[ReplacementClass::class] = '/missing/changed-input.php';
        $classMap['Acme\\Pending\\AddedToInput'] = '/missing/added-input.php';
        $pending = $loader->getClassMap();
        $pending[ReplacementClass::class] = '/missing/changed-result.php';
        $pending['Acme\\Pending\\AddedToResult'] = '/missing/added-result.php';

        $this->assertNotSame($classMap, $loader->getClassMap());
        $this->assertNotSame($pending, $loader->getClassMap());
        $this->assertSame($original, $loader->getClassMap());
        $this->assertTrue(class_exists(ReplacementClass::class));
        $this->assertSame('replacement', ReplacementClass::SOURCE);
        $this->assertSame([], $loader->getClassMap());
        $loader->unregister();
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testInterceptorCanReplaceMappedLoadingAndCanBeCancelled(): void
    {
        $mappedFile = $this->fixture('InterceptedClass.php');
        $interceptedFile = tempnam(sys_get_temp_dir(), 'super-kernel-autoloader-');

        if (false === $interceptedFile) {
            $this->fail('Unable to create the intercepted class fixture.');
        }

        try {
            $written = file_put_contents($interceptedFile, <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SuperKernel\Autoloader\Tests\Fixture;

                final class InterceptedClass
                {
                    public const string SOURCE = 'interceptor';
                }
                PHP);
            $this->assertNotFalse($written);
            $observed = [];
            $loader = $this->register([
                SuperKernel\Autoloader\Tests\Fixture\InterceptedClass::class => $mappedFile,
            ]);
            $interceptorId = $loader->addInterceptor(
                static function (string $class, string $file) use (&$observed, $interceptedFile): bool {
                    $observed = [
                        $class,
                        $file,
                    ];
                    require $interceptedFile;

                    return true;
                },
            );

            $this->assertTrue(class_exists(InterceptedClass::class));
            $this->assertSame([
                SuperKernel\Autoloader\Tests\Fixture\InterceptedClass::class,
                $mappedFile,
            ], $observed);
            $this->assertSame('interceptor', InterceptedClass::SOURCE);
            $this->assertSame([], $loader->getClassMap());
            $this->assertTrue($loader->removeInterceptor($interceptorId));
            $this->assertFalse($loader->removeInterceptor($interceptorId));
            $loader->unregister();
        } finally {
            if (is_file($interceptedFile)) {
                unlink($interceptedFile);
            }
        }
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When class-loader bootstrap unexpectedly fails
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testCancelledInterceptorDoesNotObserveOrSuppressTheLoad(): void
    {
        $calls = 0;
        $loader = $this->register([
            SuperKernel\Autoloader\Tests\Fixture\CancelledInterceptorClass::class => $this->fixture(
                'CancelledInterceptorClass.php',
            ),
        ]);
        $interceptorId = $loader->addInterceptor(
            static function (string $_class, string $_file) use (&$calls): bool {
                ++$calls;

                return true;
            },
        );

        $this->assertTrue($loader->removeInterceptor($interceptorId));
        $this->assertTrue(class_exists(CancelledInterceptorClass::class));
        $this->assertSame(0, $calls);
        $loader->unregister();
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testHandledButUndeclaredClassRemainsPending(): void
    {
        $mappedFile = $this->fixture('InterceptedClass.php');
        $loader = $this->register([
            SuperKernel\Autoloader\Tests\Fixture\InterceptedClass::class => $mappedFile,
        ]);
        $interceptorId = $loader->addInterceptor(static fn(string $_class, string $_file): bool => true);

        $this->assertFalse(class_exists(InterceptedClass::class));
        $this->assertSame([
            SuperKernel\Autoloader\Tests\Fixture\InterceptedClass::class => $mappedFile,
        ], $loader->getClassMap());

        $loader->removeInterceptor($interceptorId);
        $this->assertTrue(class_exists(InterceptedClass::class));
        $loader->unregister();
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testOneLoadChainPrunesEveryClassItDeclares(): void
    {
        $loader = $this->register([
            SuperKernel\Autoloader\Tests\Fixture\FirstMappedClass::class  => $this->fixture('FirstMappedClass.php'),
            SuperKernel\Autoloader\Tests\Fixture\SecondMappedClass::class => $this->fixture('SecondMappedClass.php'),
        ]);

        $this->assertTrue(class_exists(FirstMappedClass::class));
        $this->assertTrue(class_exists(SecondMappedClass::class, false));
        $this->assertSame([], $loader->getClassMap());
        $loader->unregister();
    }

    /** @throws Throwable When class-loader bootstrap unexpectedly fails */
    public function testUnsafeRemovalExposesEveryPendingMappingAndTheFirstClass(): void
    {
        $classMap = [
            'Acme\\Pending\\FirstClass'  => '/missing/first.php',
            'Acme\\Pending\\SecondClass' => '/missing/second.php',
        ];
        $loader = $this->register($classMap);

        try {
            $loader->unregister();
            $this->fail('Pending classmap entries must prevent removal.');
        } catch (UnloadedClassMapException $exception) {
            $this->assertSame($classMap, $exception->classMap());
            $this->assertSame('Acme\\Pending\\FirstClass', $exception->firstClassName());
            $this->assertStringContainsString('Acme\\Pending\\FirstClass', $exception->getMessage());
        }

        $this->assertSame([
            $loader,
        ], spl_autoload_functions());
        $this->assertTrue($loader->isRegistered());
    }

    /**
     * @param array<class-string, non-empty-string> $classMap
     *
     * @throws Throwable When class-loader bootstrap fails
     */
    private function register(array $classMap = []): AutoLoaderInterface
    {
        $root = $this->createStub(PackageMetadataInterface::class);
        $root->method('version')->willReturn(null);
        $root->method('name')->willReturn('fixture/application');
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('all')->willReturn([
            $root,
        ]);
        $packages->method('classes')->willReturn($classMap);

        return new AutoLoaderProvider()($packages);
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__) . '/Fixture/' . $file;
    }
}
