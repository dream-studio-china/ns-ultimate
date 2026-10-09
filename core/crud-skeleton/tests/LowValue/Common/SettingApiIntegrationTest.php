<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Entity\Setting;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

#[Group('low-value')]
final class SettingApiIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $em->createQuery('DELETE FROM App\\Common\\Entity\\Setting')->execute();
        self::ensureKernelShutdown();
    }

    public function testCreateAndReadSettingViaManageApi(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/settings', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'key' => 'qiniu.access_key',
            'value' => 'sk-test',
            'type' => 'string',
            'groupName' => 'qiniu',
            'label' => 'Access Key',
            'description' => 'Qiniu Access Key',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $created['code']);
        self::assertSame('qiniu.access_key', $created['data']['key']);
        self::assertSame('sk-test', $created['data']['value']);

        $client->request('GET', '/api/v1/manage/settings/' . $created['data']['id']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('qiniu.access_key', $detail['data']['key']);
    }

    public function testCreateSettingViaEntityManager(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $setting = new Setting('qiniu.secret_key');
        $setting->setValue('secret-123');
        $setting->setGroupName('qiniu');
        $setting->setLabel('Secret Key');

        $em->persist($setting);
        $em->flush();

        self::assertNotNull($setting->getId());

        $found = $em->find(Setting::class, $setting->getId());
        self::assertInstanceOf(Setting::class, $found);
        self::assertSame('qiniu.secret_key', $found->getKey());
        self::assertSame('secret-123', $found->getValue());
    }
}
