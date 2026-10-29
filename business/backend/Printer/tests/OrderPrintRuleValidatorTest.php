<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\OrderPrintRuleMatcher;
use NsUltimate\Business\Printer\Service\OrderPrintRuleValidator;
use PHPUnit\Framework\TestCase;

final class OrderPrintRuleValidatorTest extends TestCase
{
    public function testAcceptsBoundedRulesAndRejectsDuplicateIds(): void
    {
        $rule = ['id' => 'kitchen-paid', 'name' => 'Kitchen', 'enabled' => true,
            'events' => ['paid'], 'match' => 'all', 'conditions' => [],
            'deviceUuid' => UUID::v4(), 'templateUuid' => UUID::v4(), 'copies' => 1];
        $validator = new OrderPrintRuleValidator(new OrderPrintRuleMatcher());
        $validator->validate([$rule]);
        self::assertTrue(true);
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate([$rule, $rule]);
    }

    public function testRejectsInvalidOutputEvenWhenRuleIsDisabled(): void
    {
        $rule = ['id' => 'kitchen', 'name' => 'Kitchen', 'enabled' => false,
            'events' => ['paid'], 'match' => 'all', 'conditions' => [],
            'deviceUuid' => 'invalid', 'templateUuid' => UUID::v4(), 'copies' => 1];
        $this->expectException(\InvalidArgumentException::class);
        (new OrderPrintRuleValidator(new OrderPrintRuleMatcher()))->validate([$rule]);
    }

    public function testStoreOwnershipAndTemplateKindAreChecked(): void
    {
        $store = new Store();
        $device = new PrinterDevice(new PrinterAccount(), 'Printer', 'serial', PrinterDevice::KIND_RECEIPT, $store->getUuid());
        $template = new PrinterTemplate('Receipt');
        $rule = ['id' => 'receipt', 'name' => 'Receipt', 'enabled' => true,
            'events' => ['paid'], 'match' => 'all', 'conditions' => [],
            'deviceUuid' => $device->getUuid(), 'templateUuid' => $template->getUuid(), 'copies' => 1];
        $devices = $this->createStub(EntityRepository::class);
        $devices->method('findOneBy')->willReturn($device);
        $templates = $this->createStub(EntityRepository::class);
        $templates->method('findOneBy')->willReturn($template);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[PrinterDevice::class, $devices], [PrinterTemplate::class, $templates]]);
        $validator = new OrderPrintRuleValidator(new OrderPrintRuleMatcher());
        $validator->validateForStore([$rule], $store, $em);
        self::assertTrue(true);
        $device->setStoreUuid(UUID::v4());
        $this->expectException(\InvalidArgumentException::class);
        $validator->validateForStore([$rule], $store, $em);
    }
}
