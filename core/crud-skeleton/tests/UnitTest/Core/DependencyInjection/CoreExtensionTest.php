<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\DependencyInjection;

use App\Core\DependencyInjection\CoreExtension;
use App\Core\Serializer\SerializerContextFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CoreExtensionTest extends TestCase
{
    public function testLoadRegistersCoreServiceDefinitions(): void
    {
        $container = new ContainerBuilder();
        $extension = new CoreExtension();

        $extension->load([], $container);

        self::assertTrue($container->hasDefinition(SerializerContextFactory::class));
        self::assertTrue($container->hasDefinition('app.serializer.datetime_normalizer'));
        self::assertTrue($container->hasDefinition('app.serializer.method_normalizer'));
        self::assertTrue($container->hasDefinition('app.serializer.circular_reference_handler'));
        self::assertTrue($container->hasDefinition(\App\Core\EventListener\ExceptionInterceptor::class));
        self::assertTrue($container->hasDefinition(\App\Core\EventListener\ControllerListener::class));
    }

    public function testLoadedDefinitionsCarryExpectedConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new CoreExtension();

        $extension->load([], $container);

        $datetime = $container->getDefinition('app.serializer.datetime_normalizer');
        self::assertSame(\Symfony\Component\Serializer\Normalizer\DateTimeNormalizer::class, $datetime->getClass());
        self::assertTrue($datetime->hasTag('serializer.normalizer'));

        $method = $container->getDefinition('app.serializer.method_normalizer');
        self::assertSame(\App\Core\Serializer\Normalizer\FlatNormalizer::class, $method->getClass());
        self::assertSame('serializer.normalizer.object', $method->getDecoratedService()[0]);
    }

    public function testLoadIsIdempotentAcrossMultipleCalls(): void
    {
        $container = new ContainerBuilder();
        $extension = new CoreExtension();

        $extension->load([], $container);
        $extension->load([], $container);

        self::assertTrue($container->hasDefinition('app.serializer.datetime_normalizer'));
    }
}
