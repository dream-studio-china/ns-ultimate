<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\EventListener;

use App\Core\EventListener\LocaleListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocaleListenerTest extends TestCase
{
    public function testSetsLocaleFromAcceptLanguageChinese(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'zh-CN,en;q=0.9');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh', $request->getLocale());
    }

    public function testSetsLocaleFromAcceptLanguageEnglish(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'en-US,zh-CN;q=0.8');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
    }

    public function testSetsLocaleFromQueryParameter(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products?_locale=zh', 'GET');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh', $request->getLocale());
    }

    public function testQueryParameterTakesPriorityOverHeader(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products?_locale=zh', 'GET');
        $request->headers->set('Accept-Language', 'en-US');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh', $request->getLocale());
    }

    public function testFallsBackToDefaultLocaleForUnsupportedLanguage(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR');
        // en is Symfony's default_locale in translation.yaml

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        // If no supported locale matches, locale is unchanged (defaults to 'en')
        self::assertSame('en', $request->getLocale());
    }

    public function testNoHeaderOrQueryKeepsDefaultLocale(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
    }

    public function testZhHansIsMappedToZh(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'zh-Hans');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh', $request->getLocale());
    }

    public function testZhTWIsMappedToZhHant(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'zh-TW');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh_Hant', $request->getLocale());
    }

    public function testJapaneseIsMappedToJa(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'ja-JP');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('ja', $request->getLocale());
    }

    public function testTraditionalChineseFromQueryParameter(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products?_locale=zh_Hant', 'GET');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('zh_Hant', $request->getLocale());
    }

    public function testJapaneseFromQueryParameter(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products?_locale=ja', 'GET');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('ja', $request->getLocale());
    }

    public function testRespectsQualityFactorOrdering(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        // ja-JP has higher quality than en-US
        $request->headers->set('Accept-Language', 'fr-FR,ja-JP;q=0.9,en-US;q=0.5');

        $event = $this->createEvent($request);
        $listener->onKernelRequest($event);

        self::assertSame('ja', $request->getLocale());
    }

    public function testSubRequestDoesNotChangeLocale(): void
    {
        $listener = new LocaleListener();
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('Accept-Language', 'zh-CN');

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );
        $listener->onKernelRequest($event);

        // Sub-requests should not change locale
        self::assertSame('en', $request->getLocale());
    }

    private function createEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
