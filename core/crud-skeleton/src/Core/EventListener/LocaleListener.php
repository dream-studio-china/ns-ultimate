<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

class LocaleListener
{
    /**
     * Supported locale codes. Requests with unsupported locales
     * fall back to the configured default_locale (en).
     */
    private const array SUPPORTED_LOCALES = ['en', 'zh', 'zh_Hant', 'ja'];

    /**
     * Maps verbose locale codes (zh-CN, zh-Hans, en-US, ja-JP, etc.) to
     * their canonical forms used by Symfony's translator.
     */
    private const array LOCALE_MAP = [
        'zh-CN' => 'zh',
        'zh-Hans' => 'zh',
        'zh-HK' => 'zh_Hant',
        'zh-TW' => 'zh_Hant',
        'zh-Hant' => 'zh_Hant',
        'zh-Hant-TW' => 'zh_Hant',
        'en-US' => 'en',
        'en-GB' => 'en',
        'ja-JP' => 'ja',
        'ja_JP' => 'ja',
    ];

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->query->has('_locale')) {
            $rawLocale = $request->query->get('_locale');
            if (is_string($rawLocale)) {
                $locale = $this->resolveLocale($rawLocale);
                if ($locale !== null) {
                    $request->setLocale($locale);
                }
            }

            return;
        }

        $acceptLanguage = $request->headers->get('Accept-Language');

        if ($acceptLanguage === null) {
            return;
        }

        $preferred = $this->parseAcceptLanguage($acceptLanguage);

        if ($preferred === null) {
            return;
        }

        $request->setLocale($preferred);
    }

    /**
     * Parse the Accept-Language header and return the most preferred
     * supported locale, or null if none match.
     */
    private function parseAcceptLanguage(string $header): ?string
    {
        $entries = [];

        foreach (explode(',', $header) as $item) {
            $parts = explode(';q=', trim($item));
            $lang = trim($parts[0]);
            $quality = isset($parts[1]) ? (float) $parts[1] : 1.0;
            $entries[$lang] = $quality;
        }

        arsort($entries, SORT_NUMERIC);

        foreach (array_keys($entries) as $lang) {
            $resolved = $this->resolveLocale($lang);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Resolve a locale code to its canonical form.
     * Returns null for unsupported locales.
     */
    private function resolveLocale(string $locale): ?string
    {
        if (\in_array($locale, self::SUPPORTED_LOCALES, true)) {
            return $locale;
        }

        if (isset(self::LOCALE_MAP[$locale])) {
            return self::LOCALE_MAP[$locale];
        }

        return null;
    }
}
