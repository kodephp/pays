<?php

declare(strict_types=1);

namespace Kode\Pays\Tests\Core;

use Kode\Pays\Core\WalletManager;
use PHPUnit\Framework\TestCase;

/**
 * 钱包账户 ID：32 位十六进制随机值，不可预测、同用户多次绑定不重复。
 *
 * 旧版为 md5(userId . type . microtime(true))，时间成分可被推算；
 * 换 random_bytes 后与创建时刻/入参无关联。
 */
final class WalletManagerIdTest extends TestCase
{
    private function bindAndGetId(WalletManager $wm, string $user, string $type, string $account): string
    {
        $wm->bind($user, $type, ['account' => $account]);
        $wallets = $wm->getUserWallets($user);

        self::assertNotEmpty($wallets);
        $id = $wallets[array_key_last($wallets)]['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testGeneratedIdIs32HexChars(): void
    {
        $wm = new WalletManager();
        $id = $this->bindAndGetId($wm, 'user_001', 'wechat_wallet', 'oUpF8uMuAJO');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testGeneratedIdsNeverRepeatForSameUserAndType(): void
    {
        $wm = new WalletManager();
        $id1 = $this->bindAndGetId($wm, 'user_001', 'wechat_wallet', 'acct_a');
        $id2 = $this->bindAndGetId($wm, 'user_001', 'wechat_wallet', 'acct_b');

        self::assertNotSame($id1, $id2);
    }

    public function testGeneratedIdsDifferAcrossUsers(): void
    {
        $wm = new WalletManager();
        $id1 = $this->bindAndGetId($wm, 'user_a', 'bank_card', '622202************1234');
        $id2 = $this->bindAndGetId($wm, 'user_b', 'bank_card', '622202************5678');

        self::assertNotSame($id1, $id2);
    }
}
