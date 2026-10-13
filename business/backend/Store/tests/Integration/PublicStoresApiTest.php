<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Tests\Integration;

use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Route;

final class PublicStoresApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testAnonymousRequestListsOnlyActiveStoresWithPublicFields(): void
    {
        $client = self::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $activeStore = (new Store('public-demo', 'Public Demo', 'Asia/Shanghai'))
            ->setCurrency('USD')
            ->setContact(['phone' => '+1-555-0100'])
            ->setAddress(['city' => 'Seattle']);
        $inactiveStore = new Store('private-demo', 'Private Demo');
        $inactiveStore->suspend();
        $entityManager->persist($activeStore);
        $entityManager->persist($inactiveStore);
        $entityManager->flush();

        $client->request('GET', '/api/v1/public/stores');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['code']);
        self::assertSame('SUCCESS', $body['message']);
        self::assertCount(1, $body['data']);
        self::assertSame([
            'uuid' => $activeStore->getUuid(),
            'code' => 'public-demo',
            'name' => 'Public Demo',
            'timezone' => 'Asia/Shanghai',
            'currency' => 'USD',
            'contact' => ['phone' => '+1-555-0100'],
            'address' => ['city' => 'Seattle'],
        ], $body['data'][0]);

        $client->request('GET', '/api/v1/public/stores/'.$activeStore->getUuid());
        self::assertResponseIsSuccessful();
        $detail = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($body['data'][0], $detail['data']);

        $client->request('GET', '/api/v1/public/stores/'.$inactiveStore->getUuid());
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublicRouteIsOnlyImportedUnderVersionedPublicApi(): void
    {
        $client = self::createClient();
        $routes = $client->getContainer()->get('router')->getRouteCollection();

        $listRoute = $routes->get('api-v1-public-stores-list');
        $detailRoute = $routes->get('api-v1-public-stores-detail');
        self::assertInstanceOf(Route::class, $listRoute);
        self::assertInstanceOf(Route::class, $detailRoute);
        self::assertSame('/api/v1/public/stores', $listRoute->getPath());
        self::assertSame('/api/v1/public/stores/{id}', $detailRoute->getPath());
        self::assertNull($routes->get('public-stores-list'));
    }
}
