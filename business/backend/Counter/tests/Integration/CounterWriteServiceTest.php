<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Tests\Integration;

use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use NsUltimate\Integration\Backend\Kernel;

final class CounterWriteServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const STORE_UUID = '00000000-0000-4000-8000-0000000000AB';

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFieldSchemaIsTheSingleActionSpecificSource(): void
    {
        $create = CounterWriteService::fieldSchema('create');
        self::assertSame(['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'], array_keys($create));
        self::assertSame(['type' => 'uuid', 'pattern' => CounterWriteService::fieldSchema('create')['storeUuid']['pattern'], 'patternFlags' => 'i', 'requiredOnCreate' => true], $create['storeUuid']);
        self::assertSame(['type' => 'string', 'maxLength' => 50, 'requiredOnCreate' => true, 'nonEmpty' => true, 'trimForValidation' => true], $create['code']);
        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'nullable' => true], $create['capacity']);
        self::assertSame(['code', 'name', 'capacity', 'sort', 'enabled'], array_keys(CounterWriteService::fieldSchema('update')));
        self::assertSame([], CounterWriteService::fieldSchema('delete'));

        $this->assertThrows(\InvalidArgumentException::class, static fn () => CounterWriteService::fieldSchema('archive'));
    }

    public function testNormalizationAndValidationShareTheSchema(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CounterWriteService::class);

        self::assertSame(
            ['storeUuid' => strtolower(self::STORE_UUID), 'code' => 'Desk 1', 'name' => 'Front desk', 'capacity' => null, 'sort' => 0, 'enabled' => true],
            $service->normalize('create', [
                'storeUuid' => '00000000-0000-4000-8000-0000000000ab',
                'code' => ' Desk 1 ', 'name' => ' Front desk ', 'capacity' => null, 'sort' => 0, 'enabled' => true,
            ]),
        );
        self::assertSame(['name' => 'Renamed'], $service->normalize('update', ['name' => ' Renamed ']));
        self::assertSame([], $service->normalize('update', [], true));
        self::assertSame([], $service->normalize('delete', []));

        foreach ([
            ['code' => 'desk', 'name' => 'Missing store'],
            ['storeUuid' => self::STORE_UUID, 'name' => 'Missing code'],
            ['storeUuid' => self::STORE_UUID, 'code' => 'desk', 'name' => ''],
            ['storeUuid' => self::STORE_UUID, 'code' => str_repeat('x', 51), 'name' => 'Long code'],
            ['storeUuid' => self::STORE_UUID, 'code' => 'desk', 'name' => 'Bad capacity', 'capacity' => 0],
            ['storeUuid' => self::STORE_UUID, 'code' => 'desk', 'name' => 'Bad sort', 'sort' => -1],
            ['storeUuid' => self::STORE_UUID, 'code' => 'desk', 'name' => 'Bad enabled', 'enabled' => 'true'],
            ['storeUuid' => self::STORE_UUID, 'code' => 'desk', 'name' => 'Unknown', 'metadata' => []],
        ] as $changes) {
            $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('create', $changes));
        }
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('update', ['storeUuid' => self::STORE_UUID]));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('update', []));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('delete', ['name' => 'x']));
    }

    public function testLookupUniquenessStoreAndUnusedRules(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(CounterWriteService::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $store = new Store('service-store', 'Service Store');
        $em->persist($store);
        $counter = (new Counter())->setStoreUuid($store->getUuid())->setCode('service-desk')->setName('Service Desk');
        $em->persist($counter);
        $em->flush();

        self::assertSame($counter, $service->find((string) $counter->getId()));
        self::assertSame($counter, $service->find($counter->getUuid()));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->find('1garbage'));
        $this->assertThrows(\OutOfBoundsException::class, static fn () => $service->find('999999'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->assertUnique($store->getUuid(), 'service-desk'));
        self::assertSame($store, $service->requireStore($store->getUuid()));
        self::assertNull($service->findStore('00000000-0000-4000-8000-0000000000ff'));

        $store->suspend();
        $em->flush();
        // The service deliberately has no lifecycle policy; the adapter owns it.
        self::assertFalse($service->requireStore($store->getUuid())->isActive());

        $binding = (new OrderCounter())->setTradeOrderUuid('00000000-0000-4000-8000-000000000099')
            ->setStoreUuid($store->getUuid())->setCounter($counter);
        $em->persist($binding);
        $em->flush();
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->assertUnreferenced($counter));

        $staged = $service->create(['storeUuid' => $store->getUuid(), 'code' => 'staged', 'name' => 'Staged']);
        $service->persist($staged);
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($staged));
    }

    public function testStandardAdminPreservesBaselineSerializerBehavior(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = new Store('admin-service-store', 'Admin Service Store');
        $em->persist($store);
        $em->flush();

        $client->jsonRequest('POST', '/api/v1/manage/counters', [
            'storeUuid' => $store->getUuid(),
            'code' => ' Admin Desk ',
            'name' => ' Admin Desk ',
            'capacity' => null,
            'sort' => 2,
            'metadata' => ['note' => 'admin-only'],
            'enabled' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $created = $created['data'] ?? $created;
        $id = (int) $created['id'];
        $em->clear();
        $counter = $em->find(Counter::class, $id);
        self::assertInstanceOf(Counter::class, $counter);
        self::assertSame('Admin Desk', $counter->getCode());
        self::assertSame('Admin Desk', $counter->getName());
        self::assertNull($counter->getCapacity());
        self::assertSame(2, $counter->getSort());
        self::assertTrue($counter->getEnabled());
        self::assertSame(['note' => 'admin-only'], $counter->getMetadata());

        // Metadata-only updates preserve the other stored fields.
        $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, ['metadata' => ['note' => 'updated']]);
        self::assertResponseStatusCodeSame(200);
        $em->clear();
        $counter = $em->find(Counter::class, $id);
        self::assertSame('Admin Desk', $counter->getCode());
        self::assertSame('Admin Desk', $counter->getName());
        self::assertNull($counter->getCapacity());
        self::assertSame(2, $counter->getSort());
        self::assertTrue($counter->getEnabled());
        self::assertSame(['note' => 'updated'], $counter->getMetadata());

        // Original serializer boundaries: null int sort fails with the existing 500
        // message; truthy/falsy scalars are coerced by the serializer.
        $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, ['sort' => null]);
        self::assertResponseStatusCodeSame(500);
        $failure = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Failed to denormalize attribute "sort"', $failure['message']);
        $em->clear();
        self::assertSame(2, $em->find(Counter::class, $id)->getSort());

        $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, ['enabled' => 'yes']);
        self::assertResponseStatusCodeSame(200);
        $em->clear();
        self::assertTrue($em->find(Counter::class, $id)->getEnabled());

        $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, ['enabled' => 0]);
        self::assertResponseStatusCodeSame(200);
        $em->clear();
        self::assertFalse($em->find(Counter::class, $id)->getEnabled());

        $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, ['enabled' => null]);
        self::assertResponseStatusCodeSame(500);
        $failure = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Failed to denormalize attribute "enabled"', $failure['message']);
        $em->clear();
        self::assertFalse($em->find(Counter::class, $id)->getEnabled());

        foreach ([
            [['capacity' => 0], 'capacity must be a positive integer.'],
            [['sort' => -1], 'sort must be a non-negative integer.'],
            [['metadata' => 'x'], 'metadata must be an object or null.'],
            [['name' => ''], 'name cannot be empty.'],
        ] as [$payload, $message]) {
            $client->jsonRequest('PUT', '/api/v1/manage/counters/'.$id, $payload);
            self::assertResponseStatusCodeSame(400);
            $failure = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($message, $failure['message']);
        }
    }

    public function testStandardAdminPreservesLegacyUuidFormsWithoutLowercasing(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $uppercaseStore = new Store('uppercase-store', 'Uppercase Store');
        $em->persist($uppercaseStore);
        $em->flush();
        $uppercase = strtoupper($uppercaseStore->getUuid());
        $em->getConnection()->executeStatement('UPDATE store SET uuid = ? WHERE id = ?', [$uppercase, $uppercaseStore->getId()]);
        $client->jsonRequest('POST', '/api/v1/manage/counters', ['storeUuid' => $uppercase, 'code' => 'upper-desk', 'name' => 'Upper Desk']);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $created = $created['data'] ?? $created;
        self::assertSame($uppercase, $created['storeUuid']);

        $compactStore = new Store('compact-store', 'Compact Store');
        $em->persist($compactStore);
        $em->flush();
        $compact = str_replace('-', '', $compactStore->getUuid());
        $em->getConnection()->executeStatement('UPDATE store SET uuid = ? WHERE id = ?', [$compact, $compactStore->getId()]);
        $client->jsonRequest('POST', '/api/v1/manage/counters', ['storeUuid' => $compact, 'code' => 'compact-desk', 'name' => 'Compact Desk']);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $created = $created['data'] ?? $created;
        self::assertSame($compact, $created['storeUuid']);

        $client->jsonRequest('POST', '/api/v1/manage/counters', ['storeUuid' => 'not-a-uuid', 'code' => 'invalid', 'name' => 'Invalid']);
        self::assertResponseStatusCodeSame(400);
        $failure = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('storeUuid must be a valid UUID.', $failure['message']);
    }

    private function assertThrows(string $class, callable $callback): void
    {
        $thrown = null;
        try {
            $callback();
        } catch (\Throwable $error) {
            $thrown = $error;
        }
        self::assertInstanceOf($class, $thrown);
    }
}
