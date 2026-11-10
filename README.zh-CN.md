<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Autoloader

`super-kernel/autoloader` 用一个类加载器替换进程中所有已注册的自动加载器；该加载器的类映射在创建时根据 `skernel build` 的元数据确定，并允许代码拦截已映射类的加载。

## 安装

```bash
composer require super-kernel/autoloader
```

本包需要 `skernel build` 生成、由 `super-kernel/metadata` 恢复的元数据，以及一个用来解析加载器的 SuperKernel 容器，例如 `super-kernel/di`。`super-kernel/aop` 会在应用启动时解析它。

## 提供的能力

- 一个 SPL 自动加载器，从同一个类映射中加载根包和所有已安装包的类，根包的类优先。
- 待加载的类映射：所有尚未加载的已映射类。
- 在包含已映射文件之前运行、并且可以自行加载该类的拦截器。
- 在所有已映射类都加载完成之后移除加载器。

它不会：

- 退回到 Composer 或其他任何加载器：接管会在进程的剩余时间里移除它们；
- 在创建之后接受新的映射；
- 加载不在类映射中的类。

## 快速开始

项目中的任何类都在类映射中。将一个类保存为 `src/Billing/Invoice.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Billing;

final class Invoice
{
}
```

解析加载器，观察它加载该类，并看到该类离开待加载映射。将脚本保存为 `bin/loader.php`：

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

构建项目并运行脚本：

```bash
skernel build
php bin/loader.php
```

它会打印：

```text
bool(true)
int(1)
Loading Src\Billing\Invoice from Invoice.php
bool(false)
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Contract\AutoLoaderInterface` | 接口 | 加载器：待加载的类映射、拦截器和移除；从容器解析它会替换所有已注册的自动加载器。 |
| `SuperKernel\Autoloader\Provider\AutoLoaderProvider` | 类 | `AutoLoaderInterface` 的工厂提供者；它根据包元数据构建类映射并完成接管。 |
| `SuperKernel\Autoloader\AutoLoader` | 类 | 加载器；`new AutoLoader($classMap)` 创建一个不注册的加载器，可用于测试。 |

## 需要了解的行为

- 解析 `AutoLoaderInterface` 会移除所有已注册的自动加载器（包括 Composer 的），并只注册新的加载器。被移除的加载器永远不会被恢复。如果创建加载器失败，之前的自动加载器会被恢复，异常向外传播。
- 不在类映射中的类，或者其映射文件缺失的类，不会被任何加载器加载。
- 类映射来自构建元数据：添加、删除或移动类之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。
- 只要还有已映射的类尚未加载，`unregister()` 就会抛出 `SuperKernel\Autoloader\Exception\UnloadedClassMapException`（消息例如 `Class "Src\Billing\Invoice" has not been loaded.`），并且不做任何更改。
- 对于为空或包含空字节的类名或路径，`new AutoLoader($classMap)` 抛出 `SuperKernel\Autoloader\Exception\InvalidClassMapException`。
- 每次解析都会创建一个新的加载器，由容器保留。在长时间运行的运行时中，请在每个工作进程中分别解析加载器。

## 文档

开发者文档用完整示例介绍接管、类映射、拦截器、安全移除，以及各种错误及其消息：[SuperKernel Autoloader](https://github.com/super-kernel/.github/blob/main/docs/components/autoloader.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
