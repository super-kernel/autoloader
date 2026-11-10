<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Autoloader

`super-kernel/autoloader` replaces every registered autoloader of a process with one class loader whose class map is fixed when it is created, from the metadata of `skernel build`, and lets code intercept the loading of a mapped class.

## Installation

```bash
composer require super-kernel/autoloader
```

The package needs the metadata that `skernel build` generates, which `super-kernel/metadata` restores, and a SuperKernel container, such as `super-kernel/di`, to resolve the loader. `super-kernel/aop` resolves it when an application starts.

## What it provides

- One SPL autoloader that loads the classes of the root and every installed package from a single class map, the root's classes first.
- The pending class map: every mapped class that is not loaded yet.
- Interceptors that run before a mapped file is included and may load the class themselves.
- Removal of the loader once every mapped class has loaded.

It does not:

- fall back to Composer's or any other loader: a takeover removes them for the rest of the process;
- accept new mappings after it is created;
- load a class that is not in the class map.

## Quick start

Any class of the project is in the class map. Save one as `src/Billing/Invoice.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Billing;

final class Invoice
{
}
```

Resolve the loader, watch it load the class, and see the class leave the pending map. Save the script as `bin/loader.php`:

```php
<?php

declare(strict_types=1);

use Src\Billing\Invoice;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\AutoLoaderInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$loader = ApplicationContext::getContainer()->get(AutoLoaderInterface::class);

var_dump($loader->isRegistered(), count(spl_autoload_functions()));

$loader->addInterceptor(static function (string $class, string $file): bool {
    echo 'Loading ', $class, ' from ', basename($file), PHP_EOL;

    return false;
});

new Invoice();

var_dump(isset($loader->getClassMap()[Invoice::class]));
```

Build the project and run the script:

```bash
skernel build
php bin/loader.php
```

It prints:

```text
bool(true)
int(1)
Loading Src\Billing\Invoice from Invoice.php
bool(false)
```

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Contract\AutoLoaderInterface` | Interface | The loader: pending class map, interceptors, and removal; resolving it from the container replaces every registered autoloader. |
| `SuperKernel\Autoloader\Provider\AutoLoaderProvider` | Class | The factory provider of `AutoLoaderInterface`; it builds the class map from the package metadata and performs the takeover. |
| `SuperKernel\Autoloader\AutoLoader` | Class | The loader; `new AutoLoader($classMap)` creates one without registering it, which is useful in tests. |

## Behavior to know

- Resolving `AutoLoaderInterface` removes every registered autoloader, including Composer's, and registers the new loader alone. The removed loaders are never restored. If creating the loader fails, the previous autoloaders are restored and the exception propagates.
- A class that is not in the class map, or whose mapped file is missing, is not loaded by anything.
- The class map comes from the build metadata: run `skernel build` again after adding, removing, or moving a class, or start with `skernel run`, which builds first.
- `unregister()` raises `SuperKernel\Autoloader\Exception\UnloadedClassMapException`, with a message such as `Class "Src\Billing\Invoice" has not been loaded.`, while any mapped class is not loaded yet, and changes nothing.
- `new AutoLoader($classMap)` raises `SuperKernel\Autoloader\Exception\InvalidClassMapException` for an empty class name or path, or one with a null byte.
- Each resolution creates a new loader, which a container keeps. In a long-running runtime, resolve the loader separately in each worker process.

## Documentation

The developer documentation describes the takeover, the class map, interceptors, safe removal, and the errors and their messages with complete examples: [SuperKernel Autoloader](https://github.com/super-kernel/.github/blob/main/docs/components/autoloader.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
