<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Controller\App;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Entity\User;
use App\Payment\Controller\App\InvoiceController;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class InvoiceControllerTest extends TestCase
{
    private InvoiceServiceInterface $service;
    private InvoiceController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(InvoiceServiceInterface::class);
        $this->controller = new InvoiceController($this->service);
    }

    private function setUpController(User $user): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/invoices/1/pay/mock', 'POST'));

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', $translator);

        $this->controller->setContainer($container);
        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function invoiceForUser(User $user): Invoice
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getPayer')->willReturn($user);
        $invoice->method('getId')->willReturn(1);

        return $invoice;
    }

    public function testPayActionReturnsSuccessWhenPayerMatches(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->setUpController($user);

        $invoice = $this->invoiceForUser($user);
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('pay')->willReturn(new PaymentResult(new Invoice(), Invoice::STATUS_PAYING));

        $request = Request::create('/api/v1/app/invoices/1/pay/mock', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['autoPaid' => true], JSON_THROW_ON_ERROR));

        $response = $this->controller->payAction($request, 1, 'mock');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
        self::assertSame('Payment started', $body['message']);
    }

    public function testPayActionReturnsWarningWhenServiceThrows(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->setUpController($user);

        $invoice = $this->invoiceForUser($user);
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);
        $this->service->method('pay')->willThrowException(new \RuntimeException('payment failed'));

        $request = Request::create('/api/v1/app/invoices/1/pay/mock', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([], JSON_THROW_ON_ERROR));

        $response = $this->controller->payAction($request, 1, 'mock');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(400, $body['code']);
        self::assertSame('payment failed', $body['message']);
    }

    public function testPayActionReturnsNotFoundWhenPayerMismatch(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);
        $this->setUpController($user);

        $other = $this->createMock(User::class);
        $other->method('getId')->willReturn(7);

        $invoice = $this->invoiceForUser($other);
        $this->service->method('get')->with(['id' => 1])->willReturn($invoice);

        $request = Request::create('/api/v1/app/invoices/1/pay/mock', 'POST');
        $response = $this->controller->payAction($request, 1, 'mock');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Invoice not found.', $body['message']);
    }
}
