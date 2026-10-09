<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Entity\Material;
use App\Inventory\Entity\InventoryOutboxMessage;
use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\Message\ReservationRequestedMessage;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use App\Inventory\Service\InventoryOutboxService;
use App\Inventory\Service\InventoryMessageIntegrityException;
use App\Inventory\Service\InventoryServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class InventoryMessagingAndApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ([
            'App\\Inventory\\Entity\\InventoryOutboxMessage',
            'App\\Inventory\\Entity\\InventoryConsumedEvent',
            'App\\Inventory\\Entity\\LedgerEntry',
            'App\\Inventory\\Entity\\ReservationLine',
            'App\\Inventory\\Entity\\Reservation',
            'App\\Inventory\\Entity\\RecipeLine',
            'App\\Inventory\\Entity\\SpecificationRecipe',
            'App\\Inventory\\Entity\\Stock',
            'App\\Inventory\\Entity\\Material',
        ] as $entity) {
            $em->createQuery('DELETE FROM ' . $entity . ' entity')->execute();
        }
        self::ensureKernelShutdown();
    }

    public function testManagementApisCreateMaterialRecipeAndAdjustStock(): void
    {
        $client = static::createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/v1/manage/inventory/materials', [
            'code' => 'api-material',
            'name' => 'API Material',
            'kind' => 'raw',
            'unit' => 'kg',
        ]);
        self::assertResponseStatusCodeSame(201);
        $materialUuid = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['uuid'];

        $client->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/' . $materialUuid . '/policy', ['allowNegativeStock' => true]);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/' . $materialUuid . '/adjust', ['quantityDelta' => '3.000000', 'reason' => 'test', 'referenceId' => 'api-adjustment']);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/manage/inventory/stocks/00000000-0000-4000-8000-000000000001/' . $materialUuid);
        self::assertResponseIsSuccessful();
        self::assertSame('3.000000', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['availableQuantity']);
        $client->request('GET', '/api/v1/manage/inventory/stocks');
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['materialUuid' => $materialUuid, 'quantityPerUnit' => '1.000000']],
        ]);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/api/v1/manage/inventory/recipes');
        self::assertResponseIsSuccessful();
        $recipeUuid = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'][0]['uuid'];
        $client->jsonRequest('PUT', '/api/v1/manage/inventory/recipes/' . $recipeUuid, ['status' => 'inactive']);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['materialUuid' => $materialUuid, 'quantityPerUnit' => '1.000000']],
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testManagementApisRequireAdminAuthentication(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/v1/manage/inventory/materials', [
            'code' => 'unauthorized',
            'name' => 'Unauthorized',
            'kind' => 'raw',
            'unit' => 'piece',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testManagementApisRejectInvalidDomainInput(): void
    {
        $client = static::createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/v1/manage/inventory/materials', ['code' => 'incomplete']);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', '/api/v1/manage/inventory/recipes', ['specificationUuid' => 'not-a-recipe', 'lines' => []]);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/store/material/policy', ['allowNegativeStock' => 'yes']);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', '/api/v1/manage/inventory/stocks/store/material/adjust', ['quantityDelta' => '1.000000']);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/api/v1/manage/inventory/stocks/store/00000000-0000-4000-8000-000000000069');
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('PUT', '/api/v1/manage/inventory/stocks/store/00000000-0000-4000-8000-000000000069/policy', ['allowNegativeStock' => true]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testRequestedAndReleasedMessagesAreIdempotent(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000011';
        $specificationUuid = '00000000-0000-4000-8000-000000000012';
        $material = new Material($specificationUuid, 'Finished', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $inventory = $container->get(InventoryServiceInterface::class);
        $inventory->setStockAllowNegative($storeUuid, $material->getUuid(), true);

        $envelope = [
            'eventId' => '00000000-0000-4000-8000-000000000013',
            'type' => 'inventory.reservation.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000014',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000014',
                'storeUuid' => $storeUuid,
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000015',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000016',
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000017',
                    'catalogReference' => $specificationUuid,
                    'quantity' => '2.000000',
                ]],
                'requestedAt' => '2026-07-26T00:00:00.123456+00:00',
                'expiresAt' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
            ],
        ];
        $handler = $container->get(\App\Inventory\MessageHandler\ReservationRequestedHandler::class);
        $handler(new ReservationRequestedMessage($envelope));
        $handler(new ReservationRequestedMessage($envelope));
        self::assertSame('-2.000000', $inventory->getStockView($storeUuid, $material->getUuid())['availableQuantity']);

        $release = [
            'eventId' => '00000000-0000-4000-8000-000000000018',
            'type' => 'inventory.reservation.release.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000014',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000014',
                'storeUuid' => $storeUuid,
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000015',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000016',
                'reason' => 'test cancellation',
                'requestedAt' => '2026-07-26T00:01:00+00:00',
            ],
        ];
        $container->get(\App\Inventory\MessageHandler\ReservationReleaseRequestedHandler::class)(new ReservationReleaseRequestedMessage($release));
        self::assertSame('0.000000', $inventory->getStockView($storeUuid, $material->getUuid())['availableQuantity']);
    }

    public function testOutboxPublisherMarksKnownTopicPublished(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $container->get(InventoryOutboxService::class)->record('inventory.reservation.confirmed.v1', 'inventory_reservation', '00000000-0000-4000-8000-000000000021', [
            'reservationId' => '00000000-0000-4000-8000-000000000021',
            'storeUuid' => '00000000-0000-4000-8000-000000000022',
            'tradeOrderUuid' => '00000000-0000-4000-8000-000000000023',
            'storeOrderUuid' => '00000000-0000-4000-8000-000000000024',
            'confirmedAt' => '2026-07-26T00:00:00+00:00',
        ]);
        $container->get(EntityManagerInterface::class)->flush();
        $application = new Application(static::getContainer()->get('kernel'));
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:inventory:outbox:publish']), new NullOutput()));
        self::assertCount(0, $container->get(InventoryOutboxMessageRepository::class)->findUnpublished());
    }

    public function testOutboxPublisherDefersUnsupportedTopics(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $container->get(InventoryOutboxService::class)->record('inventory.unsupported.v1', 'inventory', 'unsupported', []);
        $container->get(EntityManagerInterface::class)->flush();
        $application = new Application(static::getContainer()->get('kernel'));
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:inventory:outbox:publish']), new NullOutput()));
        $message = $container->get(InventoryOutboxMessageRepository::class)->findOneBy(['topic' => 'inventory.unsupported.v1']);
        self::assertInstanceOf(InventoryOutboxMessage::class, $message);
        self::assertFalse($message->isPublished());
    }

    public function testOutboxPublisherMapsEveryInventoryOutcome(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $outbox = $container->get(InventoryOutboxService::class);
        foreach (['inventory.reservation.rejected.v1', 'inventory.reservation.released.v1'] as $topic) {
            $outbox->record($topic, 'inventory_reservation', '00000000-0000-4000-8000-000000000071', []);
        }
        $container->get(EntityManagerInterface::class)->flush();
        $application = new Application(static::getContainer()->get('kernel'));
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:inventory:outbox:publish']), new NullOutput()));
        self::assertCount(0, $container->get(InventoryOutboxMessageRepository::class)->findUnpublished());
    }

    public function testOutboxPublisherRecordsTransportFailures(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $container->get(InventoryOutboxService::class)->record('inventory.reservation.confirmed.v1', 'inventory_reservation', '00000000-0000-4000-8000-000000000072', []);
        $container->get(EntityManagerInterface::class)->flush();
        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport unavailable');
            }
        };
        $command = new \App\Inventory\Command\PublishOutboxCommand($container->get(InventoryOutboxMessageRepository::class), $failingBus);
        self::assertSame(0, $command->run(new ArrayInput([]), new NullOutput()));
        $message = $container->get(InventoryOutboxMessageRepository::class)->findOneBy(['aggregateId' => '00000000-0000-4000-8000-000000000072']);
        self::assertInstanceOf(InventoryOutboxMessage::class, $message);
        self::assertFalse($message->isPublished());
    }

    public function testMessageHandlersRejectMalformedEnvelopes(): void
    {
        $client = static::createClient();
        $requestHandler = $client->getContainer()->get(\App\Inventory\MessageHandler\ReservationRequestedHandler::class);
        $releaseHandler = $client->getContainer()->get(\App\Inventory\MessageHandler\ReservationReleaseRequestedHandler::class);

        foreach ([
            new ReservationRequestedMessage([]),
            new ReservationRequestedMessage(['type' => 'inventory.reservation.requested', 'version' => 1, 'eventId' => 'invalid', 'aggregateId' => 'aggregate', 'payload' => []]),
            new ReservationRequestedMessage([
                'type' => 'inventory.reservation.requested',
                'version' => 1,
                'eventId' => '00000000-0000-4000-8000-000000000061',
                'aggregateId' => '00000000-0000-4000-8000-000000000062',
                'payload' => [
                    'reservationId' => '00000000-0000-4000-8000-000000000062',
                    'storeUuid' => '00000000-0000-4000-8000-000000000063',
                    'tradeOrderUuid' => '00000000-0000-4000-8000-000000000064',
                    'storeOrderUuid' => '00000000-0000-4000-8000-000000000065',
                    'items' => [],
                    'requestedAt' => 'invalid',
                    'expiresAt' => 'invalid',
                ],
            ]),
        ] as $message) {
            try {
                $requestHandler($message);
                self::fail('Expected invalid reservation request.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        foreach ([
            new ReservationReleaseRequestedMessage([]),
            new ReservationReleaseRequestedMessage([
                'type' => 'inventory.reservation.release.requested',
                'version' => 1,
                'eventId' => '00000000-0000-4000-8000-000000000066',
                'aggregateId' => '00000000-0000-4000-8000-000000000067',
                'payload' => ['reservationId' => '00000000-0000-4000-8000-000000000067'],
            ]),
        ] as $message) {
            try {
                $releaseHandler($message);
                self::fail('Expected invalid reservation release.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReleaseMessageRejectsMismatchedReservationCorrelations(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryServiceInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000111';
        $material = new Material('release-correlation', 'Release correlation', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $inventory->adjustStock($storeUuid, $material->getUuid(), '1.000000', 'receipt');
        $inventory->reserve('00000000-0000-4000-8000-000000000112', $storeUuid, '00000000-0000-4000-8000-000000000113', '00000000-0000-4000-8000-000000000114', [['lineId' => '00000000-0000-4000-8000-000000000115', 'catalogReference' => $material->getCode(), 'quantity' => '1.000000']]);

        $message = new ReservationReleaseRequestedMessage([
            'eventId' => '00000000-0000-4000-8000-000000000116',
            'type' => 'inventory.reservation.release.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000112',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000112',
                'storeUuid' => '00000000-0000-4000-8000-000000000117',
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000113',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000114',
                'reason' => 'cancelled',
                'requestedAt' => '2026-07-26T00:01:00+00:00',
            ],
        ]);

        $this->expectException(InventoryMessageIntegrityException::class);
        $container->get(\App\Inventory\MessageHandler\ReservationReleaseRequestedHandler::class)($message);
    }

    public function testInboxRejectsEventIdReusedWithDifferentPayload(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryServiceInterface::class);
        $storeUuid = '00000000-0000-4000-8000-000000000121';
        $material = new Material('inbox-conflict', 'Inbox conflict', Material::KIND_FINISHED, 'piece');
        $em->persist($material);
        $em->flush();
        $inventory->setStockAllowNegative($storeUuid, $material->getUuid(), true);
        $envelope = [
            'eventId' => '00000000-0000-4000-8000-000000000122',
            'type' => 'inventory.reservation.requested',
            'version' => 1,
            'aggregateId' => '00000000-0000-4000-8000-000000000123',
            'payload' => [
                'reservationId' => '00000000-0000-4000-8000-000000000123',
                'storeUuid' => $storeUuid,
                'tradeOrderUuid' => '00000000-0000-4000-8000-000000000124',
                'storeOrderUuid' => '00000000-0000-4000-8000-000000000125',
                'items' => [['lineId' => '00000000-0000-4000-8000-000000000126', 'catalogReference' => $material->getUuid(), 'quantity' => '1.000000']],
                'requestedAt' => '2026-07-26T00:00:00+00:00',
                'expiresAt' => '2026-07-27T00:00:00+00:00',
            ],
        ];
        $handler = $container->get(\App\Inventory\MessageHandler\ReservationRequestedHandler::class);
        $handler(new ReservationRequestedMessage($envelope));
        $envelope['payload']['items'][0]['quantity'] = '2.000000';

        $this->expectException(InventoryMessageIntegrityException::class);
        $handler(new ReservationRequestedMessage($envelope));
    }
}
