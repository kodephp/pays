<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Gateway\Wechat;

use Kode\Pays\Core\PayException;
use Kode\Pays\Gateway\Wechat\WechatOauth;
use Kode\Pays\Tests\MockHttpClient;
use PHPUnit\Framework\TestCase;

class WechatOauthTest extends TestCase
{
    public function testBuildAuthorizeUrlContainsExpectedParams(): void
    {
        $oauth = new WechatOauth(['app_id' => 'wx123', 'app_secret' => 'sec']);

        $url = $oauth->buildAuthorizeUrl('https://example.com/cb', 'snsapi_base', 'STATE');

        $this->assertStringContainsString('open.weixin.qq.com/connect/oauth2/authorize', $url);
        $this->assertStringContainsString('appid=wx123', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fcb', $url);
        $this->assertStringContainsString('scope=snsapi_base', $url);
        $this->assertStringContainsString('state=STATE', $url);
        $this->assertStringContainsString('#wechat_redirect', $url);
    }

    public function testGetOpenIdReturnsParsedToken(): void
    {
        $oauth = new WechatOauth(
            ['app_id' => 'wx123', 'app_secret' => 'sec'],
            new MockHttpClient([
                'sns/oauth2/access_token' => json_encode([
                    'access_token' => 'ATOKEN',
                    'openid' => 'oABC',
                    'scope' => 'snsapi_base',
                ]),
            ]),
        );

        $result = $oauth->getOpenId('THE_CODE');

        $this->assertSame('oABC', $result['openid']);
        $this->assertSame('ATOKEN', $result['access_token']);
    }

    public function testGetOpenIdThrowsOnErrorCode(): void
    {
        $oauth = new WechatOauth(
            ['app_id' => 'wx123', 'app_secret' => 'sec'],
            new MockHttpClient([
                'sns/oauth2/access_token' => json_encode([
                    'errcode' => 40029,
                    'errmsg' => 'invalid code',
                ]),
            ]),
        );

        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/网页授权失败/');

        $oauth->getOpenId('BAD_CODE');
    }

    public function testMissingConfigThrows(): void
    {
        $this->expectException(PayException::class);
        $this->expectExceptionMessageMatches('/app_id 与 app_secret/');

        new WechatOauth(['app_id' => 'wx123']);
    }
}
