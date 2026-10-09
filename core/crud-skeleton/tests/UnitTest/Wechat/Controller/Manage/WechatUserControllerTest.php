<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Controller\Manage;

use App\Core\Controller\RestController;
use App\Wechat\Controller\Manage\WechatUserController;
use App\Wechat\Service\WechatUserServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class WechatUserControllerTest extends TestCase
{
    private WechatUserServiceInterface $service;
    private WechatUserController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(WechatUserServiceInterface::class);
        $this->controller = new WechatUserController($this->service);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    public function testCommonFilterReturnsEmptyArray(): void
    {
        $ref = new \ReflectionMethod($this->controller, 'commonFilter');
        $result = $ref->invoke($this->controller);

        self::assertSame([], $result);
    }

    public function testListActionReturnsAllRecordsForAdmin(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/wechat-users', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('list')
            ->with([], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([], $body['data']);
    }
}
