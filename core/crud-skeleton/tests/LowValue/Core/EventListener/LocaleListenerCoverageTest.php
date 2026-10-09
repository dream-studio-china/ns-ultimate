<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Core\EventListener;


use PHPUnit\Framework\Attributes\Group;
use App\Core\EventListener\LocaleListener;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers the remaining branch of LocaleListener (missing Accept-Language header).
 */
#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class LocaleListenerCoverageTest extends TestCase
{
    public function testMissingAcceptLanguageLeavesPreSetLocaleIntact(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->remove('Accept-Language');
        $request->setLocale('ja');

        self::assertFalse($request->query->has('_locale'));
        self::assertNull($request->headers->get('Accept-Language'));

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $listener->onKernelRequest($event);

        self::assertSame('ja', $request->getLocale());
    }
}
