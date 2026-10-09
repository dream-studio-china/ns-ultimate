<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Controller\Manage;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Entity\User;
use App\Payment\Controller\Manage\InvoiceController;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class InvoiceControllerTest extends TestCase
{
    private InvoiceServiceInterface $service;
    private EntityManagerInterface $entityManager;
    private WorkflowInterface $workflow;
    private InvoiceController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(InvoiceServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflow = $this->createMock(WorkflowInterface::class);

        $this->controller = new InvoiceController($this->service, $this->entityManager, $this->workflow);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function request(string $uri, string $method, array $content = []): Request
    {
        return Request::create($uri, $method, server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function testCreateActionReturnsSuccessWithPayerAndParsesAmount(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices', 'POST'));
        $this->injectDependencies($requestStack);

        $user = $this->createMock(User::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($user);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($repository);

        $invoice = new Invoice();
        $captured = null;
        $this->service->method('createInvoice')->willReturnCallback(
            static function (CreateInvoiceRequest $request) use ($invoice, &$captured): Invoice {
                $captured = $request;
                return $invoice;
            }
        );

        $response = $this->controller->createAction($this->request('/api/v1/manage/invoices', 'POST', [
            'sourceType' => 'manual',
            'sourceId' => 'src-1',
            'scene' => Invoice::SCENE_ORDER,
            'amount' => '12.34',
            'payer' => 7,
            'subject' => 'Subject',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertInstanceOf(CreateInvoiceRequest::class, $captured);
        self::assertSame(1234, $captured->amount);
        self::assertSame('CNY', $captured->currency);
        self::assertSame($user, $captured->payer);
    }

    public function testCreateActionReturnsWarningWhenRequiredFieldMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices', 'POST'));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($this->request('/api/v1/manage/invoices', 'POST', [
            'sourceType' => 'manual',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('sourceId is required.', $body['message']);
    }

    public function testCreateActionReturnsWarningWhenServiceThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('createInvoice')->willThrowException(
            new \InvalidArgumentException('Invoice amount cannot be negative.')
        );

        $response = $this->controller->createAction($this->request('/api/v1/manage/invoices', 'POST', [
            'sourceType' => 'manual',
            'sourceId' => 'src-1',
            'scene' => Invoice::SCENE_ORDER,
            'amount' => -100,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('Invoice amount cannot be negative.', $body['message']);
    }

    public function testPayActionReturnsSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/pay/mock', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('pay')->willReturn(new PaymentResult($invoice, Invoice::STATUS_PAYING));

        $response = $this->controller->payAction(
            $this->request('/api/v1/manage/invoices/1/pay/mock', 'POST', ['autoPaid' => true]),
            1,
            'mock'
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Payment started', $body['message']);
    }

    public function testPayActionReturnsWarningWhenServiceThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/pay/mock', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('pay')->willThrowException(new \RuntimeException('gateway not registered'));

        $response = $this->controller->payAction(
            $this->request('/api/v1/manage/invoices/1/pay/mock', 'POST', []),
            1,
            'mock'
        );

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('gateway not registered', $body['message']);
    }

    public function testCancelActionReturnsWarningWhenInvoiceNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/999/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->cancelAction(
            $this->request('/api/v1/manage/invoices/999/cancel', 'POST', []),
            999
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Invoice not found.', $body['message']);
    }

    public function testCancelActionReturnsSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('cancel')->willReturn($invoice);

        $response = $this->controller->cancelAction(
            $this->request('/api/v1/manage/invoices/1/cancel', 'POST', ['reason' => 'api cancel']),
            1
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Invoice cancelled', $body['message']);
    }

    public function testCancelActionReturnsWarningWhenServiceThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('cancel')->willThrowException(new \RuntimeException('cannot cancel'));

        $response = $this->controller->cancelAction(
            $this->request('/api/v1/manage/invoices/1/cancel', 'POST', ['reason' => 'x']),
            1
        );

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('cannot cancel', $body['message']);
    }

    public function testRefundActionReturnsWarningWhenInvoiceNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/999/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->refundAction(
            $this->request('/api/v1/manage/invoices/999/refund', 'POST', ['amount' => 1, 'reason' => 'x']),
            999
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Invoice not found.', $body['message']);
    }

    public function testRefundActionReturnsWarningWhenAmountOrReasonMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);

        $response = $this->controller->refundAction(
            $this->request('/api/v1/manage/invoices/1/refund', 'POST', ['amount' => '']),
            1
        );

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('amount and reason are required.', $body['message']);
    }

    public function testRefundActionReturnsSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('refund')->willReturn(
            new PaymentRefundResult($invoice, 500, Invoice::STATUS_PARTIAL_REFUNDED, 'refund-1')
        );

        $response = $this->controller->refundAction(
            $this->request('/api/v1/manage/invoices/1/refund', 'POST', ['amount' => '5.00', 'reason' => 'test']),
            1
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Refund processed', $body['message']);
    }

    public function testRefundActionReturnsWarningWhenServiceThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('refund')->willThrowException(new \RuntimeException('refund failed'));

        $response = $this->controller->refundAction(
            $this->request('/api/v1/manage/invoices/1/refund', 'POST', ['amount' => 100, 'reason' => 'test']),
            1
        );

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('refund failed', $body['message']);
    }

    public function testTransitionsActionReturnsWarningWhenInvoiceNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/999/transitions', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->transitionsAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Invoice not found.', $body['message']);
    }

    public function testTransitionsActionReturnsSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/invoices/1/transitions', 'GET'));
        $this->injectDependencies($requestStack);

        $invoice = new Invoice();
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->workflow->method('getEnabledTransitions')->with($invoice)->willReturn(['start_pay']);

        $response = $this->controller->transitionsAction(1);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame(['start_pay'], $body['data']);
    }
}
