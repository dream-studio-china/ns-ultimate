<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Controller\Manage;

use App\Identity\Entity\User;
use App\Wallet\Controller\Manage\VoucherCommentController;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\VoucherComment;
use App\Wallet\Service\VoucherCommentServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class VoucherCommentControllerTest extends TestCase
{
    private VoucherCommentServiceInterface $service;
    private VoucherCommentController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(VoucherCommentServiceInterface::class);
        $this->controller = new VoucherCommentController($this->service);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $container->method('get')->willReturnCallback(
            fn (string $id) => match ($id) {
                'security.token_storage' => $tokenStorage,
                default => null,
            }
        );

        $this->controller->setContainer($container);
        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function jsonRequest(array $payload): Request
    {
        return Request::create(
            '/api/v1/manage/voucher-comments',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function createComment(): VoucherComment
    {
        $user = new User();
        $user->setEmail('vc@t.com')->setUsername('vc');
        $voucher = new Voucher(
            new Wallet($user, 'CNY'),
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            10000,
            'CNY',
            'ref-1',
            'admin',
        );
        $rId = new \ReflectionProperty(Voucher::class, 'id');
        $rId->setValue($voucher, 1);

        return new VoucherComment($voucher, 'system', 'Ticket #123');
    }

    public function testCreateCommentSuccess(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest(['voucher' => 1, 'text' => 'Ticket #123']));
        $this->injectDependencies($requestStack);

        $comment = $this->createComment();
        $this->service->method('new')->willReturn($comment);
        $this->service->method('update')->willReturn($comment);
        $this->service->method('wrapInTransaction')->willReturnCallback(
            fn($cb) => $cb(null)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
    }

    public function testCreateCommentRequiresVoucherAndText(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest(['text' => 'missing voucher']));
        $this->injectDependencies($requestStack);

        $this->service->method('wrapInTransaction')->willReturnCallback(
            fn($cb) => $cb(null)
        );

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Voucher is required', $body['message']);
    }

    public function testCreateCommentRejectsInvalidJson(): void
    {
        $request = Request::create(
            '/api/v1/manage/voucher-comments',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '###',
        );
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
    }
}
