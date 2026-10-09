<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Identity\Controller\Manage;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Controller\Manage\ProfileController;
use App\Identity\Service\ProfileServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

#[Group('low-value')]
final class ProfileControllerTest extends TestCase
{
    private function createController(ProfileServiceInterface $service): ProfileController
    {
        $controller = new ProfileController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    /**
     * Security and transaction tests require kernel integration tests.
     * This unit test covers controller instantiation and basic wiring.
     */
    public function testControllerIsInstantiable(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = $this->createController($service);

        self::assertInstanceOf(ProfileController::class, $controller);
    }

    public function testAcceptedPropertiesAreDefined(): void
    {
        $service = $this->createMock(ProfileServiceInterface::class);
        $controller = new ProfileController($service);

        $ref = new \ReflectionClass($controller);
        self::assertTrue($ref->hasProperty('acceptedCreateProperties'));
        self::assertTrue($ref->hasProperty('acceptedUpdateProperties'));
    }
}
