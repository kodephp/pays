<?php

/**
 * PHPStan 专用符号桩：Swoole 为 PECL 可选扩展，无法经 composer 提供存根。
 * 按本包实际调用面声明最小 API，真实实现以运行期 extension_loaded('swoole') 为准。
 */

namespace Swoole\Coroutine {

    function create(callable $fn, mixed ...$args): int|false
    {
    }

    function run(callable $fn, mixed ...$args): mixed
    {
    }
}
