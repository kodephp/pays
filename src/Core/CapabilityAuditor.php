<?php

declare(strict_types=1);

namespace Kode\Pays\Core;

/**
 * 网关能力一致性审计器
 *
 * {@see GatewayManifest} 的能力开关是对外承诺：调用方常据此做功能门控
 * （如 `GatewayManifest::supports('wechat', 'transfer')`）。若声明与网关实际实现的
 * 能力接口脱节，就会出现「声明支持但调用即抛无此方法」的信任级缺陷。
 *
 * 本审计器以 {@see GatewayManifest::CAPABILITY_CONTRACTS} 为单一事实源，
 * 反射核对每个已注册网关的声明与实现，输出漂移报告，供架构守护测试与运行时自检使用。
 */
final class CapabilityAuditor
{
    /**
     * 漂移类型：声明具备该能力，但未实现对应能力接口
     */
    public const DRIFT_OVERCLAIMED = 'overclaimed';

    /**
     * 漂移类型：已实现能力接口，但清单未声明
     */
    public const DRIFT_UNDECLARED = 'undeclared';

    /**
     * 审计全部已注册平台的能力一致性
     *
     * @return array<int, array{gateway: string, capability: string, contract: class-string, type: string}>
     *         漂移条目列表，空数组表示声明与实现完全一致
     */
    public static function audit(): array
    {
        $drifts = [];

        foreach (GatewayManifest::all() as $name => $meta) {
            $gatewayClass = GatewayFactory::getGatewayClass($name);

            // 仅登记清单、未注册网关实现的平台无从核对，跳过
            if ($gatewayClass === null || !class_exists($gatewayClass)) {
                continue;
            }

            $capabilities = is_array($meta['capabilities'] ?? null) ? $meta['capabilities'] : [];

            foreach (GatewayManifest::CAPABILITY_CONTRACTS as $capability => $contract) {
                $declared = (bool) ($capabilities[$capability] ?? false);
                $implemented = is_subclass_of($gatewayClass, $contract);

                if ($declared === $implemented) {
                    continue;
                }

                $drifts[] = [
                    'gateway' => $name,
                    'capability' => $capability,
                    'contract' => $contract,
                    'type' => $declared ? self::DRIFT_OVERCLAIMED : self::DRIFT_UNDECLARED,
                ];
            }
        }

        return $drifts;
    }

    /**
     * 获取指定平台真实具备的扩展能力
     *
     * 以网关实际实现的能力接口为准，不依赖清单声明。
     *
     * @param string $name 平台标识
     * @return array<int, string> 能力标识列表
     */
    public static function actualCapabilities(string $name): array
    {
        $gatewayClass = GatewayFactory::getGatewayClass($name);

        if ($gatewayClass === null || !class_exists($gatewayClass)) {
            return [];
        }

        $capabilities = [];

        foreach (GatewayManifest::CAPABILITY_CONTRACTS as $capability => $contract) {
            if (is_subclass_of($gatewayClass, $contract)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * 将漂移报告格式化为可读文本
     *
     * @param array<int, array{gateway: string, capability: string, contract: class-string, type: string}> $drifts
     */
    public static function format(array $drifts): string
    {
        if ($drifts === []) {
            return '能力声明与接口实现完全一致。';
        }

        $lines = [];

        foreach ($drifts as $drift) {
            $shortContract = substr((string) strrchr('\\' . $drift['contract'], '\\'), 1);

            $lines[] = $drift['type'] === self::DRIFT_OVERCLAIMED
                ? sprintf(
                    '[虚报] %s 声明支持 %s，但未实现 %s',
                    $drift['gateway'],
                    $drift['capability'],
                    $shortContract,
                )
                : sprintf(
                    '[漏报] %s 已实现 %s，但清单未声明 %s',
                    $drift['gateway'],
                    $shortContract,
                    $drift['capability'],
                );
        }

        return implode(PHP_EOL, $lines);
    }
}
