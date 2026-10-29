<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Core\Utils\UUID;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use PHPUnit\Framework\TestCase;

final class PrinterOrderRuleTest extends TestCase
{
    public function testRuleHasStableIdentityAndCanBeValidated(): void
    {
        $rule = (new PrinterOrderRule())
            ->setStoreUuid(UUID::v4())->setName('Kitchen')->setEnabled(true)
            ->setEvents(['paid', 'accepted'])->setMatch('all')->setConditions([])
            ->setScene('full_list')->setDeviceUuid(UUID::v4())->setTemplateUuid(UUID::v4())->setCopies(1);
        $uuid = $rule->getUuid();
        (new OrderPrintRuleValidator(new OrderPrintRuleMatcher()))->validate([$rule->toRule()]);
        $rule->setName('Updated kitchen');
        self::assertSame($uuid, $rule->getUuid());
        self::assertSame($uuid, $rule->toRule()['id']);
        self::assertTrue(UUID::is_valid($uuid));
    }
}
