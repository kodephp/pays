<?php

declare(strict_types=1);

namespace Kode\Pays\Support;

use Kode\Pays\Core\PayException;

/**
 * 参数校验器
 *
 * 提供链式参数校验能力，支持必填、类型、范围、正则、枚举等校验规则。
 * 所有支付网关在业务方法入口处应使用此类校验参数，确保数据合法性。
 *
 * 使用示例：
 * ```php
 * Validator::make($params)
 *     ->required('out_trade_no', 'total_fee')
 *     ->string('out_trade_no')->maxLength('out_trade_no', 32)
 *     ->integer('total_fee')->min('total_fee', 1)
 *     ->url('notify_url')
 *     ->in('trade_type', ['NATIVE', 'JSAPI', 'APP', 'H5'])
 *     ->validate();
 * ```
 */
class Validator
{
    /**
     * 待校验参数
     *
     * @var array<string, mixed>
     */
    protected array $data;

    /**
     * 校验错误列表
     *
     * @var string[]
     */
    protected array $errors = [];

    /**
     * 是否立即抛出异常（默认否，校验完成后统一抛出）
     */
    protected bool $throwImmediately = false;

    /**
     * 私有构造，通过 make() 工厂方法创建
     *
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * 创建校验器实例
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function make(array $data): self
    {
        return new self($data);
    }

    /**
     * 设置遇到第一个错误时立即抛出异常
     *
     * @return self
     */
    public function bail(): self
    {
        $this->throwImmediately = true;

        return $this;
    }

    /**
     * 校验必填字段
     *
     * @param string ...$fields
     * @return self
     */
    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field]) || $this->data[$field] === '' || $this->data[$field] === null) {
                $this->fail("字段 {$field} 为必填项");
            }
        }

        return $this;
    }

    /**
     * 校验字段为字符串类型
     *
     * @param string ...$fields
     * @return self
     */
    public function string(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && !is_string($this->data[$field])) {
                $this->fail("字段 {$field} 必须是字符串类型");
            }
        }

        return $this;
    }

    /**
     * 校验字段为整数类型（或整型字符串）
     *
     * @param string ...$fields
     * @return self
     */
    public function integer(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field])) {
                $value = $this->data[$field];
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                    $this->fail("字段 {$field} 必须是整数类型");
                }
            }
        }

        return $this;
    }

    /**
     * 校验字段为数值类型
     *
     * @param string ...$fields
     * @return self
     */
    public function numeric(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && !is_numeric($this->data[$field])) {
                $this->fail("字段 {$field} 必须是数值类型");
            }
        }

        return $this;
    }

    /**
     * 校验字段为数组类型
     *
     * @param string ...$fields
     * @return self
     */
    public function array(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && !is_array($this->data[$field])) {
                $this->fail("字段 {$field} 必须是数组类型");
            }
        }

        return $this;
    }

    /**
     * 校验字段为布尔类型
     *
     * @param string ...$fields
     * @return self
     */
    public function boolean(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && !is_bool($this->data[$field])) {
                $this->fail("字段 {$field} 必须是布尔类型");
            }
        }

        return $this;
    }

    /**
     * 校验字符串最大长度
     *
     * @param string $field
     * @param int $max
     * @return self
     */
    public function maxLength(string $field, int $max): self
    {
        if (isset($this->data[$field]) && is_string($this->data[$field]) && mb_strlen($this->data[$field]) > $max) {
            $this->fail("字段 {$field} 长度不能超过 {$max} 个字符");
        }

        return $this;
    }

    /**
     * 校验字符串最小长度
     *
     * @param string $field
     * @param int $min
     * @return self
     */
    public function minLength(string $field, int $min): self
    {
        if (isset($this->data[$field]) && is_string($this->data[$field]) && mb_strlen($this->data[$field]) < $min) {
            $this->fail("字段 {$field} 长度不能少于 {$min} 个字符");
        }

        return $this;
    }

    /**
     * 校验数值最大值
     *
     * @param string $field
     * @param int|float $max
     * @return self
     */
    public function max(string $field, int|float $max): self
    {
        if (isset($this->data[$field]) && is_numeric($this->data[$field]) && $this->data[$field] > $max) {
            $this->fail("字段 {$field} 不能大于 {$max}");
        }

        return $this;
    }

    /**
     * 校验数值最小值
     *
     * @param string $field
     * @param int|float $min
     * @return self
     */
    public function min(string $field, int|float $min): self
    {
        if (isset($this->data[$field]) && is_numeric($this->data[$field]) && $this->data[$field] < $min) {
            $this->fail("字段 {$field} 不能小于 {$min}");
        }

        return $this;
    }

    /**
     * 校验字段值在枚举范围内
     *
     * @param string $field
     * @param array<int|string, mixed> $allowed
     * @return self
     */
    public function in(string $field, array $allowed): self
    {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $allowed, true)) {
            $this->fail("字段 {$field} 的值不在允许范围内");
        }

        return $this;
    }

    /**
     * 校验字段匹配正则表达式
     *
     * @param string $field
     * @param string $pattern
     * @return self
     */
    public function regex(string $field, string $pattern): self
    {
        if (isset($this->data[$field]) && is_string($this->data[$field])) {
            if (!preg_match($pattern, $this->data[$field])) {
                $this->fail("字段 {$field} 格式不正确");
            }
        }

        return $this;
    }

    /**
     * 校验字段为合法 URL
     *
     * @param string ...$fields
     * @return self
     */
    public function url(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && is_string($this->data[$field])) {
                if (!filter_var($this->data[$field], FILTER_VALIDATE_URL)) {
                    $this->fail("字段 {$field} 必须是合法的 URL");
                }
            }
        }

        return $this;
    }

    /**
     * 校验字段为合法邮箱
     *
     * @param string ...$fields
     * @return self
     */
    public function email(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (isset($this->data[$field]) && is_string($this->data[$field])) {
                if (!filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
                    $this->fail("字段 {$field} 必须是合法的邮箱地址");
                }
            }
        }

        return $this;
    }

    /**
     * 校验字段为合法日期格式
     *
     * @param string $field
     * @param string $format
     * @return self
     */
    public function dateFormat(string $field, string $format = 'Y-m-d H:i:s'): self
    {
        if (isset($this->data[$field]) && is_string($this->data[$field])) {
            $date = \DateTime::createFromFormat($format, $this->data[$field]);
            if ($date === false || $date->format($format) !== $this->data[$field]) {
                $this->fail("字段 {$field} 日期格式必须为 {$format}");
            }
        }

        return $this;
    }

    /**
     * 自定义校验规则
     *
     * @param string $field
     * @param callable $callback 返回 true 表示通过，返回字符串表示错误信息
     * @return self
     */
    public function custom(string $field, callable $callback): self
    {
        if (isset($this->data[$field])) {
            $result = $callback($this->data[$field], $this->data);
            if ($result !== true) {
                $this->fail(is_string($result) ? $result : "字段 {$field} 自定义校验失败");
            }
        }

        return $this;
    }

    /**
     * 执行校验，如有错误则抛出 PayException
     *
     * @throws PayException
     */
    public function validate(): void
    {
        if (!empty($this->errors)) {
            throw PayException::paramError(implode('；', $this->errors));
        }
    }

    /**
     * 获取所有错误信息
     *
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 判断是否通过校验
     *
     * @return bool
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * 判断是否未通过校验
     *
     * @return bool
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 记录错误
     *
     * @param string $message
     */
    protected function fail(string $message): void
    {
        $this->errors[] = $message;

        if ($this->throwImmediately) {
            throw PayException::paramError($message);
        }
    }
}
