<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\DefaultServiceLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DefaultServiceLocatorTest extends TestCase
{
    public function testGetEntityManager(): void
    {
        $em = new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('doctrine.orm.entity_manager')->willReturn($em);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($em, $locator->getEntityManager());
    }

    public function testGetLoggerReturnsLoggerWhenExists(): void
    {
        $logger = new \Psr\Log\NullLogger();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('logger')->willReturn(true);
        $container->method('get')->with('logger')->willReturn($logger);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($logger, $locator->getLogger());
    }

    public function testGetLoggerReturnsNullLoggerWhenMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('logger')->willReturn(false);

        $locator = new DefaultServiceLocator($container);
        $result = $locator->getLogger();
        self::assertInstanceOf(\Psr\Log\NullLogger::class, $result);
    }

    public function testGetTokenStorageWhenExists(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('security.token_storage')->willReturn(true);
        $container->method('get')->with('security.token_storage')->willReturn($tokenStorage);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($tokenStorage, $locator->getTokenStorage());
    }

    public function testGetTokenStorageWhenMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('security.token_storage')->willReturn(false);

        $locator = new DefaultServiceLocator($container);
        self::assertNull($locator->getTokenStorage());
    }

    public function testGetSerializerWhenExists(): void
    {
        $serializer = $this->createMock(\Symfony\Component\Serializer\SerializerInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($serializer);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($serializer, $locator->getSerializer());
    }

    public function testGetSerializerWhenMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('not found'));

        $locator = new DefaultServiceLocator($container);
        self::assertNull($locator->getSerializer());
    }

    public function testGetValidatorWhenExists(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('validator')->willReturn(true);
        $container->method('get')->with('validator')->willReturn($validator);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($validator, $locator->getValidator());
    }

    public function testGetValidatorWhenMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('validator')->willReturn(false);

        $locator = new DefaultServiceLocator($container);
        self::assertNull($locator->getValidator());
    }
}
