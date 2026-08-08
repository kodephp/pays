<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\SubscriptionCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\SubscriptionPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 订阅插件单元测试：校验 + 类型安全转发到网关原生方法
 */
class SubscriptionPluginTest extends TestCase
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public function testCreatePlanForwardsToCapableGateway(): void
    {
        $gateway = new class extends FakeGateway implements SubscriptionCapableInterface {
            public array $calls = [];
            public static function getName(): string { return 'subgw'; }
            public function createPlan(array $params): array { $this->calls[] = ['createPlan', $params]; return ['ok' => true]; }
            public function createSubscription(array $params): array { $this->calls[] = ['createSubscription', $params]; return ['ok' => true]; }
            public function cancelSubscription(string $id): array { $this->calls[] = ['cancelSubscription', $id]; return ['ok' => true]; }
            public function pauseSubscription(string $id): array { $this->calls[] = ['pauseSubscription', $id]; return ['ok' => true]; }
            public function resumeSubscription(string $id): array { $this->calls[] = ['resumeSubscription', $id]; return ['ok' => true]; }
            public function getSubscription(string $id): array { $this->calls[] = ['getSubscription', $id]; return ['ok' => true]; }
        };

        $plugin = new SubscriptionPlugin($gateway);
        $params = ['name' => '月度会员', 'amount' => 9900, 'currency' => 'usd', 'interval' => 'month'];
        $result = $plugin->createPlan($params);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('createPlan', $gateway->calls[0][0]);
        $this->assertSame('月度会员', $gateway->calls[0][1]['name']);
    }

    public function testCreateSubscriptionForwards(): void
    {
        $gateway = new class extends FakeGateway implements SubscriptionCapableInterface {
            public array $calls = [];
            public static function getName(): string { return 'subgw'; }
            public function createPlan(array $params): array { $this->calls[] = ['createPlan', $params]; return ['ok' => true]; }
            public function createSubscription(array $params): array { $this->calls[] = ['createSubscription', $params]; return ['ok' => true]; }
            public function cancelSubscription(string $id): array { $this->calls[] = ['cancelSubscription', $id]; return ['ok' => true]; }
            public function pauseSubscription(string $id): array { $this->calls[] = ['pauseSubscription', $id]; return ['ok' => true]; }
            public function resumeSubscription(string $id): array { $this->calls[] = ['resumeSubscription', $id]; return ['ok' => true]; }
            public function getSubscription(string $id): array { $this->calls[] = ['getSubscription', $id]; return ['ok' => true]; }
        };

        $plugin = new SubscriptionPlugin($gateway);
        $plugin->createSubscription(['customer_id' => 'cus_1', 'plan_id' => 'price_1']);

        $this->assertSame('createSubscription', $gateway->calls[0][0]);
        $this->assertSame('price_1', $gateway->calls[0][1]['plan_id']);
    }

    public function testCancelForwards(): void
    {
        $gateway = $this->capableGateway();
        $plugin = new SubscriptionPlugin($gateway);
        $plugin->cancelSubscription('sub_1');

        $this->assertSame(['cancelSubscription', 'sub_1'], $gateway->calls[0]);
    }

    public function testPauseForwards(): void
    {
        $gateway = $this->capableGateway();
        $plugin = new SubscriptionPlugin($gateway);
        $plugin->pauseSubscription('sub_1');

        $this->assertSame(['pauseSubscription', 'sub_1'], $gateway->calls[0]);
    }

    public function testResumeForwards(): void
    {
        $gateway = $this->capableGateway();
        $plugin = new SubscriptionPlugin($gateway);
        $plugin->resumeSubscription('sub_1');

        $this->assertSame(['resumeSubscription', 'sub_1'], $gateway->calls[0]);
    }

    public function testGetForwards(): void
    {
        $gateway = $this->capableGateway();
        $plugin = new SubscriptionPlugin($gateway);
        $plugin->getSubscription('sub_1');

        $this->assertSame(['getSubscription', 'sub_1'], $gateway->calls[0]);
    }

    public function testCreatePlanMissingParamThrows(): void
    {
        $gateway = $this->capableGateway();
        $plugin = new SubscriptionPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('缺少必填参数：name');

        $plugin->createPlan(['amount' => 9900, 'currency' => 'usd', 'interval' => 'month']);
    }

    public function testNonCapableGatewayThrows(): void
    {
        // FakeGateway 仅实现 GatewayInterface + HttpCapableInterface，未实现 SubscriptionCapableInterface
        $plugin = new SubscriptionPlugin(new FakeGateway());

        $this->expectException(PayException::class);
        $this->expectExceptionMessage('SubscriptionCapableInterface');

        $plugin->createPlan(['name' => 'x', 'amount' => 1, 'currency' => 'usd', 'interval' => 'month']);
    }

    public function testDiffIsPlatformAgnostic(): void
    {
        $plugin = new SubscriptionPlugin(new FakeGateway());

        $report = $plugin->diff(
            [['out_trade_no' => 'A1', 'amount' => 100, 'status' => 'PAID']],
            [['out_trade_no' => 'A1', 'amount' => 200, 'status' => 'PAID']],
        );

        $this->assertSame(1, $report['summary']['amount_mismatch_count']);
        $this->assertSame(1, $report['summary']['system_count']);
    }

    private function capableGateway(): object
    {
        return new class extends FakeGateway implements SubscriptionCapableInterface {
            public array $calls = [];
            public static function getName(): string { return 'subgw'; }
            public function createPlan(array $params): array { $this->calls[] = ['createPlan', $params]; return ['ok' => true]; }
            public function createSubscription(array $params): array { $this->calls[] = ['createSubscription', $params]; return ['ok' => true]; }
            public function cancelSubscription(string $id): array { $this->calls[] = ['cancelSubscription', $id]; return ['ok' => true]; }
            public function pauseSubscription(string $id): array { $this->calls[] = ['pauseSubscription', $id]; return ['ok' => true]; }
            public function resumeSubscription(string $id): array { $this->calls[] = ['resumeSubscription', $id]; return ['ok' => true]; }
            public function getSubscription(string $id): array { $this->calls[] = ['getSubscription', $id]; return ['ok' => true]; }
        };
    }
}
