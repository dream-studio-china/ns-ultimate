<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Controller\App;

use App\Identity\Entity\User;
use App\Wechat\Controller\App\WechatUserController;
use App\Wechat\Service\WechatUserServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class WechatUserControllerTest extends TestCase
{
    private WechatUserServiceInterface $service;
    private WechatUserController $controller;
    private Container $container;

    protected function setUp(): void
    {
        $this->service = $this->createMock(WechatUserServiceInterface::class);
        $this->controller = new WechatUserController($this->service);
        $this->container = new Container();
        $this->controller->setContainer($this->container);
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

    private function authenticateUser(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $this->container->set('security.token_storage', $tokenStorage);
    }

    public function testCommonFilterWithAuthenticatedUser(): void
    {
        $user = new User();
        $this->authenticateUser($user);

        $ref = new \ReflectionMethod($this->controller, 'commonFilter');
        $result = $ref->invoke($this->controller);

        self::assertSame(['user' => $user], $result);
    }

    public function testCommonFilterWithoutUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $this->container->set('security.token_storage', $tokenStorage);

        $ref = new \ReflectionMethod($this->controller, 'commonFilter');
        $result = $ref->invoke($this->controller);

        self::assertSame(['id' => -1], $result);
    }

    public function testListActionWithFilter(): void
    {
        $user = new User();
        $this->authenticateUser($user);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/wechat-users', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('list')
            ->with(['user' => $user], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([], $body['data']);
    }
}
