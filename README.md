# SuperKernel Autoloader

`super-kernel/autoloader` provides a process-local SPL autoloader whose classmap is
fixed at construction. It requires PHP 8.4.27 or later in the 8.4.x series and the Attribute, Composer, and Contract components.

## Registration and ownership

Resolve `SuperKernel\Contract\AutoLoaderInterface` through the DI
container. `Provider\AutoLoaderProvider` declares its `FactoryProvider` binding
and receives the existing `PackageManagerInterface` service.

The provider performs the following sequence:

1. Read every package from `all()`, including Root, and obtain each classmap with
   `classes($package->name())`. Earlier packages win collisions, so Root has priority.
2. Prepend a temporary SPL callback that looks up requested classes in that combined
   map and directly requires their files. It does not check file existence or
   suppress native loading errors.
3. Construct `new AutoLoader($classMap)` while the temporary callback and previous
   loaders remain available. Construction validates and prepares the map without
   changing SPL.
4. Unregister every current SPL callback, including the temporary callback, and
   register the new `AutoLoader` as the sole entry.
5. Return the instance as `AutoLoaderInterface`.

A successful takeover permanently discards previous loaders. Bootstrap or
construction failures propagate after restoring the original stack. Classes and
side effects from files loaded before a failure cannot be undone.

Each provider invocation creates a fresh loader. DI owns service retention;
`AutoLoader` keeps no static instance. Register separately in each persistent-runtime
worker process.

`addInterceptor()` only mutates the selected instance. It does not register it or
remove callbacks added later by other code.

### Public entry points

Consumers inject `SuperKernel\Contract\AutoLoaderInterface`; its concrete implementation is
`SuperKernel\Autoloader\AutoLoader`, bound by `Provider\AutoLoaderProvider`.
The public constructor accepts the complete classmap and is passive; factory resolution owns SPL takeover.
The shared contract describes removal failures as RuntimeException; concrete exceptions belong to Autoloader.

## Classmap behavior

Classmap keys must be non-empty class names. Values must be non-empty file paths, and neither may contain a null byte. Each mapping is checked independently with autoload disabled; already declared classes, interfaces, traits, and enums are skipped.

The provider obtains the complete classmap before constructing the loader.
Inspect pending mappings through the registered instance:

```php
$pending = $loader->getClassMap();
```

`getClassMap()` returns the pending mappings by value. Changing the input array or the
returned array does not change the loader. After any classmap file or interceptor
declares symbols, all newly declared mappings are removed, including additional
classes declared by the same file.

Relative file paths are retained as supplied and therefore follow PHP's current working-directory rules. Absolute paths are recommended for persistent processes. Missing mapped files do not fall through to another loader and remain visible in the pending classmap.

## Interception

Interceptors run in registration order before a mapped file is included. They receive the requested class name and mapped file path:

```php
$interceptorId = $loader->addInterceptor(
    static function (string $class, string $file): bool {
        $generatedFile = build_aop_version($class, $file);
        require $generatedFile;

        return true;
    },
);
```

Returning `false` continues to the next interceptor and then normal loading. Returning `true` suppresses later interceptors and the mapped file include. An interceptor that takes over loading is responsible for declaring the requested symbol. A handled request that leaves the symbol undeclared remains in the classmap.

Cancel an interceptor with its positive integer identifier:

```php
$loader->removeInterceptor($interceptorId);
```

Interceptors are only called for classmap hits because an unmapped class has no actionable file path.

## Safe removal

Before a persistent runtime starts its main service, remove the loader only after all mapped symbols have loaded:

```php
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;

try {
    $loader->unregister();
} catch (UnloadedClassMapException $exception) {
    $pending = $exception->classMap();
    $firstPendingClass = $exception->firstClassName();
}
```

When the classmap is empty, `unregister()` removes only this component's callable. It never restores loaders discarded during takeover; if no loader was registered later, the SPL stack becomes empty. Loaders registered externally after takeover remain untouched. When mappings remain, `unregister()` changes nothing and throws `UnloadedClassMapException` with the complete pending classmap and first class name.

## Errors

All component exceptions implement `ClassLoaderExceptionInterface`:

- `InvalidClassMapException` reports invalid classmap keys or paths during construction;
- `ClassLoaderRegistrationException` reports a self-removal failure;
- `UnloadedClassMapException` prevents unsafe removal while mappings remain.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
