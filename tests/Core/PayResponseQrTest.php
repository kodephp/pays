<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\PayResponse;
use Kode\Pays\Tests\TestCase;

/**
 * PayResponse 二维码访问器测试
 */
class PayResponseQrTest extends TestCase
{
    public function testGetQrContentPrefersQrCode(): void
    {
        $response = new PayResponse(['qr_code' => 'https://qr.alipay.com/xxx']);

        $this->assertSame('https://qr.alipay.com/xxx', $response->getQrContent());
    }

    public function testGetQrContentFallsBackToCodeUrl(): void
    {
        $response = new PayResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=1']);

        $this->assertSame('weixin://wxpay/bizpayurl?pr=1', $response->getQrContent());
    }

    public function testGetQrContentFallsBackToPaymentLink(): void
    {
        $response = new PayResponse(['payment_link' => 'https://stripe.com/p/xxx']);

        $this->assertSame('https://stripe.com/p/xxx', $response->getQrContent());
    }

    public function testGetQrContentFallsBackToPayUrl(): void
    {
        $response = new PayResponse(['pay_url' => 'https://pay.example.com/order/1']);

        $this->assertSame('https://pay.example.com/order/1', $response->getQrContent());
    }

    public function testGetQrContentNullWhenAbsent(): void
    {
        $response = new PayResponse(['code' => '0']);

        $this->assertNull($response->getQrContent());
    }
}
