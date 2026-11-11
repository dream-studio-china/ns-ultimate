<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Store\Entity\Store;
use App\Store\Service\StoreOrderServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use NsUltimate\Business\Store\Service\StaffOrderService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

final class StaffOrderServiceTest extends TestCase
{
    #[Test]
    public function createReplacesInheritedPromotionMetadataWithTheFreshQuote(): void
    {
        $captured = null;
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('createOrder')->willReturnCallback(
            static function (array $items, mixed $user, int $totalAmount, string $currency, ?string $notes, ?array $metadata, ?StoreContext $context) use (&$captured): Order {
                $captured = $metadata;
                return new Order();
            },
        );
        $service = new StaffOrderService(
            $orders,
            $this->createStub(StoreOrderServiceInterface::class),
            $this->createStub(WorkflowInterface::class),
        );
        $store = (new Store('STAFF-STORE', 'Staff store'))->setCurrency('CNY');
        $calculation = new PriceCalculationResult(9000, 'CNY', [], [
            'promotion' => [
                'inner' => [[
                    'promotionId' => 3,
                    'promotionName' => 'Fresh promotion',
                    'templateName' => 'Fresh template',
                    'type' => 'member_discount',
                ]],
            ],
        ]);

        $service->create($store, $calculation, null, null, [
            '_promotion' => ['applied' => [['name' => 'Inherited promotion']]],
            'counterUuid' => 'counter-1',
        ]);

        self::assertIsArray($captured);
        self::assertSame('counter-1', $captured['counterUuid']);
        self::assertSame('Fresh promotion', $captured['_promotion']['applied'][0]['name']);
        self::assertSame('inner', $captured['_promotion']['applied'][0]['phase']);
    }
}
