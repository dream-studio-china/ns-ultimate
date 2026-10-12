<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provider boundary for WeChat Pay network operations.
 *
 * Kept behind an interface so tests can substitute a deterministic fake instead of
 * calling WeChat.
 */
interface WechatPayProviderInterface
{
    /**
     * @param array<string, mixed> $body
     * @return array{prepay_id?: string|null, code_url?: string|null, raw: array<string, mixed>}
     */
    public function prepay(ResolvedWechatConfig $config, string $tradeType, array $body): array;

    /** @return array<string, mixed> */
    public function buildJsapiConfig(ResolvedWechatConfig $config, string $tradeType, string $prepayId, string $appId): array;

    /** @return array<string, mixed> */
    public function queryByOutTradeNo(ResolvedWechatConfig $config, string $outTradeNo): array;

    /** @return array<string, mixed> */
    public function parseNotify(Request $request, ResolvedWechatConfig $config): array;

    /**
     * @param array<string, mixed> $body
     * @return array{refund_id?: string|null, status?: string|null, raw: array<string, mixed>}
     */
    public function createRefund(ResolvedWechatConfig $config, array $body): array;

    /** @return array<string, mixed> */
    public function queryRefund(ResolvedWechatConfig $config, string $outRefundNo): array;

    /** @return array<string, mixed> */
    public function parseRefundNotify(Request $request, ResolvedWechatConfig $config): array;
}
