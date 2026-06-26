# Kode Pays SDK 开发指南

本文档面向希望扩展 Kode Pays SDK 的开发者，涵盖环境搭建、新增网关、编写插件、运行测试与版本发布全流程。

## 环境准备

```bash
# 克隆仓库
git clone https://github.com/kodephp/pays.git
cd pays

# 安装依赖
composer install

# 验证环境
php -v          # 需要 PHP >= 8.2
composer -V     # 需要 Composer 2.x
```

### 目录约定

```
src/
  Contract/     # 接口契约
  Core/         # 抽象基类、工厂、异常、沙箱、钱包、约束
  Config/       # 通用配置 DTO
  Event/        # 事件系统
  Pipeline/     # 管道与中间件
  Exception/    # 具体异常子类
  Facade/       # 门面类
  Gateway/      # 各支付网关实现（含网关专属 Config）
  Support/      # HTTP、签名、加密、工具类
  Plugin/       # 9 个内置插件
  Async/        # 异步通知处理
  Integration/  # kode 系列组件适配器
tests/          # 单元测试
docs/           # 文档
examples/       # 示例代码
```

## 新增支付网关

### 1. 创建目录结构

```
src/Gateway/Example/
  ExampleConfig.php    # 配置 DTO
  ExampleGateway.php   # 网关实现
```

### 2. 实现配置 DTO

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Example;

use Kode\Pays\Contract\ConfigInterface;

/**
 * Example 网关配置
 */
readonly class ExampleConfig implements ConfigInterface
{
    public function __construct(
        public string $appId,
        public string $apiKey,
        public bool $sandbox = false,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            appId: $config['app_id'] ?? '',
            apiKey: $config['api_key'] ?? '',
            sandbox: $config['sandbox'] ?? false,
        );
    }

    public function getGateway(): string
    {
        return 'example';
    }
}
```

### 3. 实现网关类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Example;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;

/**
 * Example 支付网关
 */
class ExampleGateway extends AbstractGateway
{
    /**
     * 配置初始化与必填校验
     */
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'api_key']);
    }

    /**
     * 获取当前环境基础 URL
     */
    protected function getBaseUrl(): string
    {
        // 优先使用 SandboxManager 配置的 URL
        $url = SandboxManager::getBaseUrl('example');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox
            ? 'https://sandbox.example.com/'
            : 'https://api.example.com/';
    }

    /**
     * 创建订单
     *
     * @param array<string, mixed> $params 订单参数
     * @return array<string, mixed>
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $this->validateRequired($params, ['out_trade_no', 'total_amount']);

        $response = $this->post('v1/pay', [
            'out_trade_no' => $params['out_trade_no'],
            'amount' => $params['total_amount'],
        ]);

        return $response;
    }

    public function queryOrder(string $orderId): array
    {
        return $this->get("v1/orders/{$orderId}");
    }

    public function refund(array $params): array
    {
        $this->validateRequired($params, ['order_id', 'refund_fee']);

        return $this->post('v1/refunds', $params);
    }

    public function queryRefund(string $refundId): array
    {
        return $this->get("v1/refunds/{$refundId}");
    }

    public function verifyNotify(array $data): bool
    {
        $signature = $data['signature'] ?? '';
        $payload = json_encode($data);
        $expected = hash_hmac('sha256', $payload, $this->config['api_key']);

        return hash_equals($expected, $signature);
    }

    public function closeOrder(string $orderId): array
    {
        return $this->post("v1/orders/{$orderId}/close", []);
    }

    public static function getName(): string
    {
        return 'example';
    }

    /**
     * 解析响应
     *
     * @throws GatewayException
     */
    protected function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new GatewayException('响应格式异常');
        }

        if (($data['code'] ?? '') !== 'SUCCESS') {
            throw new GatewayException(
                $data['message'] ?? '业务失败',
                $data['code'] ?? '',
            );
        }

        return $data['data'] ?? $data;
    }
}
```

### 4. 注册到工厂

在 `src/Core/GatewayFactory.php` 中：

```php
// 在 $gateways 数组中添加
'example' => \Kode\Pays\Gateway\Example\ExampleGateway::class,

// 在 $configs 数组中添加
'example' => \Kode\Pays\Gateway\Example\ExampleConfig::class,
```

### 5. 注册沙箱 URL

在 `src/Core/SandboxManager.php` 的 `$urlMap` 中添加：

```php
'example' => [
    'prod' => 'https://api.example.com/',
    'sandbox' => 'https://sandbox.example.com/',
],
```

### 6. 创建文档与示例

创建 `docs/example.md`，包含：

1. 环境要求
2. 安装方法
3. 配置说明（含 DTO 字段列表）
4. 快速开始示例
5. API 方法列表
6. 异步通知处理
7. 常见问题（沙箱、事件监听、签名算法等）

参考 `docs/wechat.md` 或 `docs/stripe.md` 的结构。

### 7. 编写测试

创建 `tests/Gateway/Example/ExampleGatewayTest.php`：

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway\Example;

use Kode\Pays\Gateway\Example\ExampleGateway;
use Kode\Pays\Tests\MockHttpClient;
use Kode\Pays\Tests\TestCase;

/**
 * Example 网关测试
 */
class ExampleGatewayTest extends TestCase
{
    public function testCreateOrderSuccess(): void
    {
        // 注入 Mock HTTP 客户端，避免真实请求
        $mock = new MockHttpClient([
            'code' => 'SUCCESS',
            'data' => ['order_id' => 'EXAMPLE_001'],
        ]);

        $gateway = new ExampleGateway([
            'app_id'  => 'test_app_id',
            'api_key' => 'test_api_key',
        ], $mock);

        $result = $gateway->createOrder([
            'out_trade_no' => 'ORDER_001',
            'total_amount' => 100,
        ]);

        $this->assertSame('EXAMPLE_001', $result['order_id']);
    }

    public function testCreateOrderThrowsOnBusinessError(): void
    {
        $this->expectException(\Kode\Pays\Exception\GatewayException::class);

        $mock = new MockHttpClient([
            'code'    => 'INVALID_AMOUNT',
            'message' => '金额错误',
        ]);

        $gateway = new ExampleGateway([
            'app_id'  => 'test_app_id',
            'api_key' => 'test_api_key',
        ], $mock);

        $gateway->createOrder([
            'out_trade_no' => 'ORDER_001',
            'total_amount' => 100,
        ]);
    }
}
```

### 8. 验证

```bash
# 运行测试
composer test -- --filter ExampleGateway

# 代码检查
composer phpcs

# 静态分析
composer phpstan
```

## 新增插件

### 1. 创建插件类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\FundConstraintValidator;
use Kode\Pays\Core\PayException;

/**
 * Example 插件
 *
 * 通过组合 GatewayInterface 扩展网关能力，使用 match 表达式按网关名称分发。
 */
class ExamplePlugin
{
    public function __construct(
        protected GatewayInterface $gateway,
        protected ?FundConstraintValidator $validator = null,
    ) {
    }

    /**
     * 执行业务逻辑
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws PayException
     */
    public function doSomething(array $params): array
    {
        // 可选：调用约束验证器做风控
        $this->validator?->validate($params);

        return match ($this->gateway::getName()) {
            'wechat' => $this->doWechatSomething($params),
            'alipay' => $this->doAlipaySomething($params),
            default  => throw PayException::invalidArgument('当前网关不支持此功能'),
        };
    }

    /**
     * 微信实现
     */
    protected function doWechatSomething(array $params): array
    {
        // 调用 $this->gateway->post() 等方法发起微信 API 请求
        return $this->gateway->post('secapi/example/do', $params);
    }

    /**
     * 支付宝实现
     */
    protected function doAlipaySomething(array $params): array
    {
        return $this->gateway->post('alipay.example.do', $params);
    }
}
```

### 2. 编写插件测试

参考 `tests/Plugin/RefundPluginTest.php`：

- Mock `GatewayInterface` 与 HTTP 响应
- 覆盖每个网关分支（wechat/alipay/stripe/paypal）
- 测试不支持网关抛出异常的场景
- 测试约束验证器触发限制的场景

### 3. 更新文档

- 在 `docs/plugins.md` 添加插件章节（配置、使用示例、注意事项）
- 在 `README.md` 添加使用示例
- 在 `docs/index.md` 的插件体系列表中添加链接

### 4. 插件设计要点

- **单一职责**：一个插件只做一件事
- **多网关支持**：通过 `match` 分发，default 抛 `PayException::invalidArgument()`
- **构造注入**：`GatewayInterface` 必须注入，`FundConstraintValidator` 等可选
- **统一参数**：对外暴露的参数命名尽量与微信/支付宝主流约定一致
- **完整注释**：所有 public 方法必须有中文注释和 `@throws` 标注

## 新增中间件

### 1. 创建中间件类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline\Middleware;

/**
 * 自定义中间件示例
 */
class ExampleMiddleware implements MiddlewareInterface
{
    public function handle(array $params, callable $next): array
    {
        // 前置处理
        $params['timestamp'] = time();

        // 调用下一层
        $response = $next($params);

        // 后置处理
        // ...

        return $response;
    }
}
```

### 2. 使用中间件

```php
use Kode\Pays\Pipeline\Pipeline;

$result = (new Pipeline())
    ->send($params)
    ->through([
        new SignMiddleware(['sign_type' => 'md5', 'key' => 'api_key']),
        new LogMiddleware($logger),
        new ExampleMiddleware(),
    ])
    ->then(fn ($p) => $gateway->rawPost($url, $p));
```

## 代码规范

### PSR-12 与项目规范

- 遵循 PSR-12 代码风格
- 所有 PHP 文件必须以 `declare(strict_types=1);` 开头
- 类名大驼峰（`WechatPayGateway`），方法名小驼峰（`createOrder`）
- 接口名加 `Interface` 后缀（`GatewayInterface`）
- 抽象类加 `Abstract` 前缀（`AbstractGateway`）
- 常量全大写下划线（`VERSION`）

### 中文注释要求

- 所有类、方法、复杂逻辑必须有中文注释
- 对外方法必须标注 `@throws`、`@param`、`@return`
- 复杂数组结构使用 DTO 代替
- 禁止在库代码中使用 `echo`、`var_dump`、`die`

### 命名冲突防范

不与 PHP 原生类/函数/常量命名冲突，例如避免使用 `HttpClient`（PHP 内置 SOAP 有同名类时需加命名空间限定）。

## 运行测试

### 单元测试

```bash
# 运行全部测试
composer test

# 运行指定测试
composer test -- --filter ExampleGatewayTest

# 生成覆盖率报告
composer test -- --coverage-html ./coverage
```

### 代码质量

```bash
# PSR-12 代码风格检查
composer phpcs

# 自动修复风格
composer phpcbf

# PHPStan 静态分析（level=8）
composer phpstan
```

### 测试规范

- 使用 PHPUnit 10+
- 测试类继承 `Kode\Pays\Tests\TestCase`
- 必须使用 Mock HTTP 客户端（`tests/MockHttpClient.php`），不发起真实支付请求
- 覆盖正常流程和异常流程
- 签名验证必须测试正反例
- 配置 DTO 测试 `fromArray()` 边界情况
- 沙箱模式测试 URL 切换

## 提交规范

遵循 [Conventional Commits](https://www.conventionalcommits.org/) 规范：

```bash
# 功能提交
git commit -m "feat(gateway): add Example payment gateway"

# 修复提交
git commit -m "fix(wechat): resolve signature issue on V3 refund"

# 文档提交
git commit -m "docs: update Example gateway documentation"

# 重构提交
git commit -m "refactor(plugin): extract refund common logic"

# 测试提交
git commit -m "test(stripe): add webhook verification tests"

# 性能优化
git commit -m "perf(http): enable connection pool"

# 工具链
git commit -m "chore: upgrade PHPUnit to 10.5"
```

类型说明：

- `feat` - 新功能
- `fix` - Bug 修复
- `docs` - 文档
- `refactor` - 重构
- `test` - 测试
- `perf` - 性能
- `chore` - 构建/工具

## 版本发布

遵循[语义化版本](https://semver.org/lang/zh-CN/)（SemVer）：`MAJOR.MINOR.PATCH`

- **MAJOR**：不兼容的 API 修改
- **MINOR**：向下兼容的功能新增
- **PATCH**：向下兼容的 Bug 修复

### 发布步骤

1. 更新 `composer.json` 的 `version` 字段
2. 更新 `README.md` 的版本徽章与特性列表
3. 提交版本变更并打标签：

```bash
git add composer.json README.md
git commit -m "release: bump version to 1.16.0"
git push origin main

# 创建带注释的标签
git tag -a v1.16.0 -m "Release v1.16.0

- 新增 Example 网关支持
- 修复退款插件 Stripe 分支签名问题
- 完善文档导航"

git push origin v1.16.0
```

4. 在 GitHub 创建 Release，附上 Release Notes（参考 Git 提交历史）
5. Packagist 会自动同步新版本

### 版本号约定

- 当前版本：见 `composer.json` 的 `version` 字段
- 主代理负责统一更新版本号
- 文档 PR 不需要修改版本号

## 文档规范

每新增一个网关或插件，必须更新对应文档：

```
docs/
  index.md           # 文档总览与导航
  quickstart.md      # 快速入门
  architecture.md    # 架构详解
  development.md     # 开发指南（本文档）
  plugins.md         # 插件总览
  wechat.md          # 各网关接入文档
  alipay.md
  ...
```

文档必须包含：

1. 环境要求
2. 安装方法
3. 配置说明（含 DTO 字段列表）
4. 快速开始示例
5. API 方法列表
6. 异步通知处理
7. 常见问题（沙箱、事件监听、签名算法等）

## 常见问题

### Q：如何在测试中 Mock HTTP 请求？

A：使用 `tests/MockHttpClient.php`，将其作为第三个参数传给网关构造函数：

```php
$mock = new MockHttpClient($expectedResponse);
$gateway = new ExampleGateway($config, $mock);
```

### Q：如何禁用 HTTPS 证书校验（仅限开发）？

A：通过 `SandboxManager` 开启沙箱模式，或在自定义 `HttpClient` 实例上设置 `verify => false`。生产环境强烈不建议关闭证书校验。

### Q：如何接入 kode 系列扩展？

A：参考 `src/Integration/` 目录下的适配器，安装对应扩展包后即可使用：

```bash
composer require kode/cache   # 缓存与分布式锁
composer require kode/limiting  # 限流保护
```

### Q：插件如何访问网关的配置？

A：通过反射或类型转换访问，或要求业务层显式传入必要参数。不要依赖网关内部属性，保持插件与网关实现解耦。
