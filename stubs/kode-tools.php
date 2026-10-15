<?php

/**
 * PHPStan 专用符号桩：kode/tools 为可选 peer 包（与内置 endroid ^5 二选一，不可同时
 * require-dev），按本包调用面声明最小 API，真实实现以运行期 class_exists 为准。
 */

namespace Kode\Tools\QrCode {

    class QrCode
    {
        public function __construct(string $content)
        {
        }

        public function setSize(int $size): void
        {
        }

        public function setFormat(string $format): void
        {
        }

        public function setLogo(string $logo): void
        {
        }

        public function render(): string
        {
        }
    }
}
