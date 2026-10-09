<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * End-to-end locale detection through the HTTP kernel:
 *  - ?_locale query-param override
 *  - Accept-Language mapping (zh-CN->zh, zh-TW->zh_Hant, ja-JP->ja, en-US->en)
 *  - fallback behavior for unsupported / missing locale hints
 *  - message translation of a known key in each locale
 *
 * Note (BUG-6): with no locale hint the ACTIVE request locale is "en" (the PHP
 * Request default). The configured default_locale "zh"
 * (config/packages/translation.yaml) only feeds the translator fallback and does
 * not make un-hinted requests use zh — see
 * docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-6.
 */
final class CoreLocaleDetectionApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    /**
     * GET /api/v1/manage/categories/999999 returns the translated
     * "Entity is not found" message in the warning envelope.
     */
    private function missingEntityMessage(array $server): string
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories/999999', server: $server);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body['message'];
    }

    public function testQueryParamOverridesAcceptLanguage(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories/999999?_locale=zh', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('实体未找到。', $body['message']);
    }

    public function testAcceptLanguageZhCnMapsToZh(): void
    {
        self::assertSame('实体未找到。', $this->missingEntityMessage(['HTTP_ACCEPT_LANGUAGE' => 'zh-CN,en-US;q=0.9']));
    }

    public function testAcceptLanguageZhTwMapsToZhHant(): void
    {
        self::assertSame('實體未找到。', $this->missingEntityMessage(['HTTP_ACCEPT_LANGUAGE' => 'zh-TW,en-US;q=0.9']));
    }

    public function testAcceptLanguageJaJpMapsToJa(): void
    {
        self::assertSame('エンティティが見つかりません。', $this->missingEntityMessage(['HTTP_ACCEPT_LANGUAGE' => 'ja-JP,en-US;q=0.9']));
    }

    public function testAcceptLanguageEnUsMapsToEn(): void
    {
        self::assertSame('Entity is not found', $this->missingEntityMessage(['HTTP_ACCEPT_LANGUAGE' => 'en-US,ja-JP;q=0.8']));
    }

    public function testQueryParamZhHantAndJa(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories/999999', server: ['HTTP_ACCEPT_LANGUAGE' => 'zh-TW']);
        self::assertSame('實體未找到。', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['message']);
        $client->request('GET', '/api/v1/manage/categories/999999', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja-JP']);
        self::assertSame('エンティティが見つかりません。', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['message']);
    }

    public function testUnsupportedLocaleLeavesEffectiveEnglishFallback(): void
    {
        // fr-FR is not in SUPPORTED_LOCALES -> the custom LocaleListener does nothing;
        // the active request locale stays at the PHP Request default ("en"), so the
        // translated message is English even though default_locale is configured as "zh".
        self::assertSame('Entity is not found', $this->missingEntityMessage(['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,en-US;q=0.5']));
    }

    public function testNoLocaleHintUsesEffectiveEnglishDefault(): void
    {
        // No ?_locale and no Accept-Language -> active request locale stays "en".
        // Note: default_locale:zh (config/packages/translation.yaml) does NOT become
        // the active locale; it only feeds the translator fallback. See BUG-6.
        self::assertSame('Entity is not found', $this->missingEntityMessage([]));
    }

    public function testSuccessMessageIsNotTranslated(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?limit=5', server: ['HTTP_ACCEPT_LANGUAGE' => 'zh-CN']);

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SUCCESS', $body['message']);
    }
}
