<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway\Wechat;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatMiniProgram;
use Kode\Pays\Tests\MockHttpClient;
use PHPUnit\Framework\TestCase;

class WechatMiniProgramTest extends TestCase
{
    public function testCode2SessionReturnsOpenIdAndSessionKey(): void
    {
        $mp = new WechatMiniProgram(
            ['app_id' => 'wx123', 'app_secret' => 'sec'],
            new MockHttpClient([
                'sns/jscode2session' => json_encode([
                    'openid' => 'oMINI',
                    'session_key' => 'SK',
                    'unionid' => 'u123',
                ]),
            ]),
        );

        $result = $mp->code2Session('THE_CODE');

        $this->assertSame('oMINI', $result['openid']);
        $this->assertSame('SK', $result['session_key']);
        $this->assertSame('u123', $result['unionid']);
    }

    public function testCode2SessionThrowsOnErrorCode(): void
    {
        $mp = new WechatMiniProgram(
            ['app_id' => 'wx123', 'app_secret' => 'sec'],
            new MockHttpClient([
                'sns/jscode2session' => json_encode([
                    'errcode' => 40029,
                    'errmsg' => 'invalid code',
                ]),
            ]),
        );

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/小程序登录失败/');

        $mp->code2Session('BAD_CODE');
    }

    public function testCode2SessionThrowsWhenOpenIdMissing(): void
    {
        $mp = new WechatMiniProgram(
            ['app_id' => 'wx123', 'app_secret' => 'sec'],
            new MockHttpClient([
                'sns/jscode2session' => json_encode(['session_key' => 'SK']),
            ]),
        );

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/openid 或 session_key/');

        $mp->code2Session('THE_CODE');
    }

    public function testMissingConfigThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/app_id 与 app_secret/');

        new WechatMiniProgram(['app_id' => 'wx123']);
    }
}
