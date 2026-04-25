# Kode Pays SDK 开发指南

本文档面向希望扩展 Kode Pays SDK 的开发者。

## 环境准备

```bash
# 克隆仓库
git clone https://github.com/kodephp/pays.git
cd pays

# 安装依赖
composer install

# 运行测试
composer test
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

### 3. 实现网关类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Gateway\Example;

use Kode\Pays\Core\AbstractGateway;
use Kode\Pays\Core\PayException;
use Kode\Pays\Core\SandboxManager;
use Kode\Pays\Exception\GatewayException;

class ExampleGateway extends AbstractGateway
{
    protected function initialize(): void
    {
        $this->validateRequired($this->config, ['app_id', 'api_key']);
    }

    protected function getBaseUrl(): string
    {
        $url = SandboxManager::getBaseUrl('example');
        if ($url !== null) {
            return $url;
        }

        return $this->sandbox
            ? 'https://sandbox.example.com/'
            : 'https://api.example.com/';
    }

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

### 4. 注册到工厂

在 `src/Core/GatewayFactory.php` 中添加：

```php
'example' => \Kode\Pays\Gateway\Example\ExampleGateway::class,
```

在 `$configs` 中添加：

```php
'example' => \Kode\Pays\Gateway\Example\ExampleConfig::class,
```

### 5. 注册沙箱 URL

在 `src/Core/SandboxManager.php` 中添加：

```php
'example' => [
    'prod' => 'https://api.example.com/',
    'sandbox' => 'https://sandbox.example.com/',
],
```

### 6. 创建文档

创建 `docs/example.md`，包含：
1. 环境要求
2. 安装方法
3. 配置说明（含 DTO 字段列表）
4. 快速开始示例
5. API 方法列表
6. 异步通知处理
7. 常见问题

### 7. 门面快捷方法（可选）

在 `src/Facade/Pay.php` 中添加：

```php
public static function example(array $config): GatewayInterface
{
    return GatewayFactory::create('example', $config);
}
```

## 新增插件

### 1. 创建插件类

```php
<?php

declare(strict_types=1);

namespace Kode\Pays\Plugin;

use Kode\Pays\Contract\GatewayInterface;
use Kode\Pays\Core\PayException;

class ExamplePlugin
{
    protected GatewayInterface $gateway;

    public function __construct(GatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function doSomething(array $params): array
    {
        return match ($this->gateway::getName()) {
            'wechat' => $this->doWechatSomething($params),
            'alipay' => $this->doAlipaySomething($params),
            default => throw PayException::invalidArgument('当前网关不支持此功能'),
        };
    }

    protected function doWechatSomething(array $params): array
    {
        // 微信特定实现
    }

    protected function doAlipaySomething(array $params): array
    {
        // 支付宝特定实现
    }
}
```

### 2. 更新文档

在 `docs/plugins.md` 中添加插件说明，在 `README.md` 中添加使用示例。

## 代码规范

- 遵循 PSR-12
- 使用 `declare(strict_types=1);`
- 类名大驼峰，方法名小驼峰
- 所有类、方法必须写中文注释
- 复杂逻辑必须写注释
- 对外方法必须标注 `@throws`

## 测试规范

```php
<?php

namespace Kode\Pays\Tests\Gateway\Example;

use Kode\Pays\Gateway\Example\ExampleGateway;
use PHPUnit\Framework\TestCase;

class ExampleGatewayTest extends TestCase
{
    public function testCreateOrder(): void
    {
        $gateway = new ExampleGateway([
            'app_id' => 'test_app_id',
            'api_key' => 'test_api_key',
        ]);

        // Mock HTTP 客户端
        // 测试正常流程和异常流程
    }
}
```

## 提交规范

```bash
# 功能提交
git commit -m "feat: add Example payment gateway"

# 修复提交
git commit -m "fix: resolve Example gateway signature issue"

# 文档提交
git commit -m "docs: update Example gateway documentation"
```

## 版本发布

1. 更新 `composer.json` 版本号
2. 更新 `CHANGELOG.md`
3. 提交并推送
4. 打标签并推送

```bash
git add -A
git commit -m "release: bump version to vX.Y.Z"
git push origin main
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```
