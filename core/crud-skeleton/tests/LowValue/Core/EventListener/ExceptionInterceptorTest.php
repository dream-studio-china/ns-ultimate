<?php

namespace App\Tests\LowValue\Core\EventListener;


use PHPUnit\Framework\Attributes\Group;
use App\Core\EventListener\ExceptionInterceptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Group('low-value')]
final class ExceptionInterceptorTest extends TestCase
{
    public function testProdEnvironmentSetsResponseForApiPath(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return (string) $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $listener = new ExceptionInterceptor(
            $translator,
            new NullLogger(),
            'prod'
        );

        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom', 7)
        );

        $listener->onKernelException($event);

        self::assertNotNull($event->getResponse());
        self::assertStringContainsString('boom', (string) $event->getResponse()?->getContent());
    }
}
