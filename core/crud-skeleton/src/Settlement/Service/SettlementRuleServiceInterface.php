<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseServiceInterface;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Entity\SettlementRuleVersion;

/** @extends BaseServiceInterface<SettlementRule> */
interface SettlementRuleServiceInterface extends BaseServiceInterface
{
    /** @param array<string, mixed> $definition */
    public function createDraftVersion(
        SettlementRule $rule,
        array $definition,
        int $priority,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
    ): SettlementRuleVersion;

    /** @param array<string, mixed> $definition */
    public function updateDraftVersion(
        SettlementRuleVersion $version,
        array $definition,
        int $priority,
        \DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo,
    ): SettlementRuleVersion;

    public function publishVersion(SettlementRule $rule, SettlementRuleVersion $version, string $publishedBy): SettlementRuleVersion;
}
