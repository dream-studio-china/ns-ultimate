<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;

/** Select enabled store rules without performing printing or modifying the order. */
final class OrderPrintRuleSelector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPrintRuleValidator $validator,
        private readonly OrderPrintRuleMatcher $matcher,
    ) {}

    /** @return list<PrinterOrderRule> */
    public function select(string $storeUuid, string $event, Order $order): array
    {
        $rules = $this->entityManager->getRepository(PrinterOrderRule::class)->findBy(
            ['storeUuid' => $storeUuid, 'enabled' => true],
            ['id' => 'ASC'],
        );
        $matches = [];
        foreach ($rules as $rule) {
            if (!$rule instanceof PrinterOrderRule) {
                continue;
            }
            $definition = $rule->toRule();
            try {
                $this->validator->validate([$definition]);
            } catch (\InvalidArgumentException) {
                // Corrupted or bypassed configuration must not trigger printing.
                continue;
            }
            if ($this->matcher->matches($definition, $event, $order)) {
                $matches[] = $rule;
            }
        }
        return $matches;
    }
}
