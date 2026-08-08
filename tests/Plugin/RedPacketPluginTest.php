<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Plugin;

use Kode\Pays\Contract\RedPacketCapableInterface;
use Kode\Pays\Core\PayException;
use Kode\Pays\Plugin\RedPacketPlugin;
use Kode\Pays\Tests\Core\FakeGateway;
use Kode\Pays\Tests\TestCase;

/**
 * 支持红包能力的假网关：记录原生方法调用，便于验证插件「校验 + 转发」行为
 */
class RedPacketCapableFakeGateway extends FakeGateway implements RedPacketCapableInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $redPacketCalls = [];

    public static function getName(): string
    {
        return 'redgw';
    }

    public function sendRedPacket(array $params): array
    {
        $this->redPacketCalls[] = ['send', $params];

        return ['ok' => true, 'mch_billno' => $params['mch_billno'] ?? ''];
    }

    public function groupRedPacket(array $params): array
    {
        $this->redPacketCalls[] = ['group', $params];

        return ['ok' => true];
    }

    public function queryRedPacket(string $mchBillNo): array
    {
        $this->redPacketCalls[] = ['query', $mchBillNo];

        return ['ok' => true];
    }
}

/**
 * 红包插件单元测试
 *
 * 验证插件只做「参数校验 + 类型安全转发」，不再承载平台组装逻辑。
 */
class RedPacketPluginTest extends TestCase
{
    public function testSendForwardsToGateway(): void
    {
        $gateway = new RedPacketCapableFakeGateway();
        $plugin = new RedPacketPlugin($gateway);

        $result = $plugin->send([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oXxx',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);

        $this->assertSame(['ok' => true, 'mch_billno' => 'REDPACK_1'], $result);
        $this->assertSame('send', $gateway->redPacketCalls[0][0]);
        $this->assertSame('REDPACK_1', $gateway->redPacketCalls[0][1]['mch_billno']);
    }

    public function testGroupForwardsToGateway(): void
    {
        $gateway = new RedPacketCapableFakeGateway();
        $plugin = new RedPacketPlugin($gateway);

        $plugin->group([
            'mch_billno' => 'GROUP_1',
            'send_name' => '某某公司',
            're_openid' => 'oXxx',
            'total_amount' => 300,
            'total_num' => 3,
            'wishing' => '裂变红包',
            'act_name' => '分享活动',
            'remark' => '分享给好友领取',
        ]);

        $this->assertSame('group', $gateway->redPacketCalls[0][0]);
    }

    public function testQueryForwardsToGateway(): void
    {
        $gateway = new RedPacketCapableFakeGateway();
        $plugin = new RedPacketPlugin($gateway);

        $result = $plugin->query('REDPACK_1');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('query', $gateway->redPacketCalls[0][0]);
        $this->assertSame('REDPACK_1', $gateway->redPacketCalls[0][1]);
    }

    public function testSendMissingRequiredThrows(): void
    {
        $gateway = new RedPacketCapableFakeGateway();
        $plugin = new RedPacketPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/缺少必填参数/');

        $plugin->send([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
        ]);
    }

    public function testNonCapableGatewayThrows(): void
    {
        $gateway = new FakeGateway(); // 仅实现基础接口，未实现 RedPacketCapableInterface
        $plugin = new RedPacketPlugin($gateway);

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/未实现红包能力接口/');

        $plugin->send([
            'mch_billno' => 'REDPACK_1',
            'send_name' => '某某公司',
            're_openid' => 'oXxx',
            'total_amount' => 100,
            'wishing' => '恭喜发财',
            'act_name' => '新年活动',
            'remark' => '参与活动领取红包',
        ]);
    }
}
