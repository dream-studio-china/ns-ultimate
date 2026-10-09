<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\DefaultServiceLocator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class DefaultServiceLocatorCoverageTest extends TestCase
{
    public function testGetRequestStackReturnsStackWhenAvailable(): void
    {
        $stack = new RequestStack();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('request_stack')->willReturn(true);
        $container->method('get')->with('request_stack')->willReturn($stack);

        $locator = new DefaultServiceLocator($container);
        self::assertSame($stack, $locator->getRequestStack());
    }

    public function testGetRequestStackReturnsNullWhenMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('request_stack')->willReturn(false);

        $locator = new DefaultServiceLocator($container);
        self::assertNull($locator->getRequestStack());
    }

    public function testGetSerializerFallsBackToLegacyServiceName(): void
    {
        $serializer = new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $id) use ($serializer): mixed {
                if ($id === \Symfony\Component\Serializer\SerializerInterface::class) {
                    throw new \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException('first');
                }

                if ($id === 'serializer') {
                    return $serializer;
                }

                throw new \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException($id);
            });

        $locator = new DefaultServiceLocator($container);
        self::assertSame($serializer, $locator->getSerializer());
    }

    #[Group('low-value')]
    public function testGetSerializerReturnsNullWhenBothServicesMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('get')
            ->willThrowException(new \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException('missing'));

        $locator = new DefaultServiceLocator($container);
        self::assertNull($locator->getSerializer());
    }
}
