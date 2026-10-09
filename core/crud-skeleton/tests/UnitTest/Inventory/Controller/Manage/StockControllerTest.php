<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Controller\Manage;

use App\Inventory\Controller\Manage\StockController;
use App\Inventory\Entity\Stock;
use App\Inventory\Service\InventoryServiceInterface;
use App\Inventory\Service\StockServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class StockControllerTest extends TestCase
{
    private StockServiceInterface $stockService;
    private InventoryServiceInterface $inventory;
    private StockController $controller;

    protected function setUp(): void
    {
        $this->stockService = $this->createMock(StockServiceInterface::class);
        $this->inventory = $this->createMock(InventoryServiceInterface::class);
        $this->controller = new StockController($this->stockService, $this->inventory);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function jsonRequest(string $method, string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            $method,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function stockView(): array
    {
        return [
            'storeUuid' => '00000000-0000-4000-8000-000000000001',
            'materialUuid' => '00000000-0000-4000-8000-000000000002',
            'exists' => true,
            'onHandQuantity' => '5.000000',
            'reservedQuantity' => '2.000000',
            'availableQuantity' => '3.000000',
            'allowNegativeStock' => false,
        ];
    }

    public function testDetailActionReturnsStockView(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002', 'GET'));
        $this->injectDependencies($requestStack);

        $this->inventory->method('getStockView')->willReturn($this->stockView());

        $response = $this->controller->detailAction('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('5.000000', $body['data']['onHandQuantity']);
    }

    public function testDetailActionReturns404WhenStockViewThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002', 'GET'));
        $this->injectDependencies($requestStack);

        $this->inventory->method('getStockView')->willThrowException(new \InvalidArgumentException('Material was not found.'));

        $response = $this->controller->detailAction('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Material was not found.', $body['message']);
    }

    public function testAdjustActionReturns400WhenPayloadInvalid(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/adjust', ['quantityDelta' => '1.000000']));
        $this->injectDependencies($requestStack);

        $response = $this->controller->adjustAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('quantityDelta and reason are required.', $body['message']);
    }

    public function testAdjustActionReturns200WhenAdjusted(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/adjust', [
            'quantityDelta' => '3.000000',
            'reason' => 'receipt',
            'referenceId' => 'ref-1',
            'allowNegativeStock' => true,
        ]));
        $this->injectDependencies($requestStack);

        $stock = $this->createMock(Stock::class);
        $this->inventory->expects(self::once())->method('adjustStock')->with(
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '3.000000',
            'receipt',
            'ref-1',
            null,
            true,
        )->willReturn($stock);

        $response = $this->controller->adjustAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAdjustActionReturns400WhenAdjustmentThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/adjust', [
            'quantityDelta' => '-1.000000',
            'reason' => 'correction',
        ]));
        $this->injectDependencies($requestStack);

        $this->inventory->method('adjustStock')->willThrowException(new \LogicException('Adjustment would make confirmed reservations unavailable.'));

        $response = $this->controller->adjustAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Adjustment would make confirmed reservations unavailable.', $body['message']);
    }

    public function testPolicyActionReturns400WhenPayloadInvalid(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/policy', ['allowNegativeStock' => 'yes']));
        $this->injectDependencies($requestStack);

        $response = $this->controller->policyAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('allowNegativeStock must be a boolean.', $body['message']);
    }

    public function testPolicyActionReturns200WhenPolicySet(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/policy', ['allowNegativeStock' => true]));
        $this->injectDependencies($requestStack);

        $stock = $this->createMock(Stock::class);
        $this->inventory->expects(self::once())->method('setStockAllowNegative')->with(
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            true,
        )->willReturn($stock);

        $response = $this->controller->policyAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPolicyActionReturns400WhenPolicyThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002/policy', ['allowNegativeStock' => true]));
        $this->injectDependencies($requestStack);

        $this->inventory->method('setStockAllowNegative')->willThrowException(new \InvalidArgumentException('Material was not found.'));

        $response = $this->controller->policyAction($requestStack->getCurrentRequest(), '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Material was not found.', $body['message']);
    }
}
