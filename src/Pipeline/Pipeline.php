<?php

declare(strict_types=1);

namespace Kode\Pays\Pipeline;

use Closure;

/**
 * 管道模式实现
 *
 * 参考 Laravel Pipeline 设计，用于处理支付请求的前置/后置逻辑。
 * 每个中间件可以对请求进行处理，然后决定是否继续传递给下一个中间件。
 *
 * 典型使用场景：
 * - 参数加密/解密
 * - 请求签名自动附加
 * - 响应数据转换
 * - 日志记录
 * - 限流控制
 */
class Pipeline
{
    /**
     * 待通过管道的数据
     */
    protected mixed $passable;

    /**
     * 中间件栈
     *
     * @var array<callable>
     */
    protected array $pipes = [];

    /**
     * 设置待处理的数据
     *
     * @param mixed $passable
     * @return self
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * 设置中间件栈
     *
     * @param array<callable> $pipes
     * @return self
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * 执行管道，最终调用目标函数
     *
     * @param Closure $destination 管道末端的目标函数
     * @return mixed
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination),
        );

        return $pipeline($this->passable);
    }

    /**
     * 构建中间件包装器
     */
    protected function carry(): Closure
    {
        return function (Closure $stack, callable $pipe): Closure {
            return function (mixed $passable) use ($stack, $pipe): mixed {
                return $pipe($passable, $stack);
            };
        };
    }

    /**
     * 准备管道末端的目标函数
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function (mixed $passable) use ($destination): mixed {
            return $destination($passable);
        };
    }
}
