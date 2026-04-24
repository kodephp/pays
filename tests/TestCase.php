<?php

declare(strict_types=1);

namespace Kode\Pays\Tests;

use Kode\Pays\Support\HttpClient;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * 测试基类
 *
 * 提供通用的测试辅助方法，如创建 Mock HTTP 客户端。
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * 创建 Mock HTTP 客户端
     *
     * @param array<string, mixed> $responses 预设响应映射 [url => response_body]
     * @return HttpClient
     */
    protected function mockHttpClient(array $responses = []): HttpClient
    {
        return new MockHttpClient($responses);
    }
}
