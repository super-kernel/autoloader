<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Autoloader

`super-kernel/autoloader` provides a process-local SPL autoloader whose classmap is fixed at construction.

## Registration and ownership

Resolve `SuperKernel\Contract\AutoLoaderInterface` through the DI container. Resolution:

1. combines the classmaps of every Composer package, including Root; earlier packages win collisions, so Root has priority;
2. constructs `AutoLoader` with that classmap while the previous loaders remain available;
3. unregisters every current SPL callback and registers the new loader as the sole entry.

A successful takeover permanently discards previous loaders. A failure propagates after the original SPL stack is restored; classes and side effects from files loaded before the failure cannot be undone. During takeover, classes load from the combined map by requiring mapped files directly, without a file-existence check or suppression of native loading errors.

Each resolution creates a fresh loader; the container owns its retention, and `AutoLoader` keeps no static instance. Register separately in each persistent-runtime worker process.

Constructing `new AutoLoader($classMap)` directly validates and prepares the map without changing SPL; only container resolution performs the takeover. `addInterceptor()` only mutates the selected instance. It does not register it or remove callbacks added later by other code.

## Classmap behavior

Classmap keys must be non-empty class names. Values must be non-empty file paths, and neither may contain a null byte. Each mapping is checked independently with autoload disabled; already declared classes, interfaces, traits, and enums are skipped.

Inspect pending mappings through the registered instance:

```php
$pending = $loader->getClassMap();
```

`getClassMap()` returns the pending mappings by value. Changing the input array or the returned array does not change the loader. After any classmap file or interceptor declares symbols, all newly declared mappings are removed, including additional classes declared by the same file.

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

Code typed against `AutoLoaderInterface` can catch removal failures as `RuntimeException`.
