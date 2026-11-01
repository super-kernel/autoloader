<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Autoloader

`super-kernel/autoloader` 提供一个进程内的 SPL 自动加载器，其类图在构造时固定。

## 注册与归属

通过 DI 容器解析 `SuperKernel\Contract\AutoLoaderInterface`。解析过程会：

1. 合并所有 Composer 包（包括 Root）的类图；冲突时先出现的包优先，因此 Root 优先级最高；
2. 在之前的加载器仍然可用时，用合并后的类图构造 `AutoLoader`；
3. 注销当前所有 SPL 回调，并把新加载器注册为唯一的条目。

接管成功后，之前的加载器会被永久丢弃。失败时会先恢复原来的 SPL 栈，再向外传播错误；失败之前已加载文件中的类和副作用无法撤销。接管期间，类通过直接 require 合并类图中映射的文件来加载，不检查文件是否存在，也不抑制原生加载错误。

每次解析都会创建一个新的加载器；由容器负责保留它，`AutoLoader` 不保存静态实例。在常驻运行时中，每个工作进程都要单独注册。

直接构造 `new AutoLoader($classMap)` 只会校验并准备类图，不会改变 SPL；只有通过容器解析才会执行接管。`addInterceptor()` 只修改所选的实例，不会注册它，也不会移除其他代码之后添加的回调。

## 类图行为

类图的键必须是非空类名，值必须是非空文件路径，两者都不能包含空字节。每条映射都在禁用自动加载的情况下单独检查；已经声明的类、接口、trait 和枚举会被跳过。

通过已注册的实例查看待加载的映射：

```php
$pending = $loader->getClassMap();
```

`getClassMap()` 按值返回待加载的映射。修改输入数组或返回的数组都不会改变加载器。任何类图文件或拦截器声明了符号之后，所有新声明的映射都会被移除，包括同一个文件声明的其他类。

相对文件路径按原样保留，因此遵循 PHP 当前工作目录的规则。常驻进程建议使用绝对路径。缺失的映射文件不会转交给其他加载器，并会继续留在待加载的类图中。

## 拦截

拦截器在映射文件被 include 之前按注册顺序运行。它们接收请求的类名和映射的文件路径：

```php
$interceptorId = $loader->addInterceptor(
    static function (string $class, string $file): bool {
        $generatedFile = build_aop_version($class, $file);
        require $generatedFile;

        return true;
    },
);
```

返回 `false` 会继续交给下一个拦截器，然后是正常加载。返回 `true` 会跳过之后的拦截器，也不再 include 映射文件。接管加载的拦截器负责声明所请求的符号。已处理但未声明该符号的请求会继续留在类图中。

用正整数标识取消拦截器：

```php
$loader->removeInterceptor($interceptorId);
```

拦截器只会在命中类图时被调用，因为未映射的类没有可操作的文件路径。

## 安全移除

在常驻运行时启动主服务之前，只有当所有映射的符号都已加载后才能移除加载器：

```php
use SuperKernel\Autoloader\Exception\UnloadedClassMapException;

try {
    $loader->unregister();
} catch (UnloadedClassMapException $exception) {
    $pending = $exception->classMap();
    $firstPendingClass = $exception->firstClassName();
}
```

类图为空时，`unregister()` 只移除本组件的回调。它从不恢复接管时丢弃的加载器；如果之后没有注册其他加载器，SPL 栈会变为空。接管之后由外部注册的加载器保持不变。仍有映射未加载时，`unregister()` 不做任何修改，并抛出 `UnloadedClassMapException`，其中包含完整的待加载类图和第一个类名。

## 错误

本组件的所有异常都实现 `ClassLoaderExceptionInterface`：

- `InvalidClassMapException` 在构造期间报告无效的类图键或路径；
- `ClassLoaderRegistrationException` 报告移除自身失败；
- `UnloadedClassMapException` 在仍有映射时阻止不安全的移除。

面向 `AutoLoaderInterface` 编写的代码可以把移除失败作为 `RuntimeException` 捕获。
