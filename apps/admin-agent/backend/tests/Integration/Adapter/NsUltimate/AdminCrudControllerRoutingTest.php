<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Controller\Manage\TagController;
use App\Common\Entity\Tag;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Business\Counter\Controller\Admin\CounterController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP-level proof that commits reach the actual standard controllers and that
 * previews/conflict checks never do.
 */
final class AdminCrudControllerRoutingTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/operations';

    private string $manifestPath;
    private ?string $previousManifest = null;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        // The concurrent manifest integration is still in flight. Keep this
        // transport/routing suite deterministic with a disposable manifest that
        // grants exactly Tag/Counter and restore any pre-existing artifact.
        $this->manifestPath = dirname(__DIR__, 7).'/var/admin-agent/admin-manifest.json';
        $this->previousManifest = is_file($this->manifestPath) ? (string) file_get_contents($this->manifestPath) : null;
        $directory = dirname($this->manifestPath);
        if (!is_dir($directory)) mkdir($directory, 0775, true);
        file_put_contents($this->manifestPath, $this->manifestJson(), LOCK_EX);
    }

    protected function tearDown(): void
    {
        if ($this->previousManifest === null) {
            @unlink($this->manifestPath);
        } else {
            file_put_contents($this->manifestPath, $this->previousManifest, LOCK_EX);
        }
        parent::tearDown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testPreviewDoesNotInvokeTheWriteControllerAndReplayInvokesItOnce(): void
    {
        [$client, $count] = $this->countingClient();
        $draft = $this->post($client, [
            'entity' => 'Tag', 'action' => 'create',
            'changes' => ['name' => 'Routing tag', 'slug' => 'routing-tag', 'color' => '#123456'],
        ]);
        self::assertSame(0, $count->value, 'Preparing a preview must never invoke a write controller.');

        $receipt = $this->commit($client, (string) $draft['operationId']);
        self::assertTrue($receipt['verified']);
        self::assertSame(1, $count->value, 'A successful commit must invoke the real standard controller exactly once.');

        $replay = $this->commit($client, (string) $draft['operationId']);
        self::assertSame($receipt['identifier'], $replay['identifier']);
        self::assertSame(1, $count->value, 'A settled replay must not invoke the write controller again.');
    }

    public function testBeforeHashConflictDoesNotInvokeTheWriteController(): void
    {
        [$client, $count] = $this->countingClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $tag = new Tag('Conflict tag', 'routing-conflict-tag');
        $em->persist($tag);
        $em->flush();
        $draft = $this->post($client, [
            'entity' => 'Tag', 'action' => 'update', 'identifier' => (string) $tag->getId(),
            'changes' => ['name' => 'Proposed routing tag'],
        ]);
        self::assertSame(0, $count->value);

        $em->getConnection()->executeStatement('UPDATE common_tag SET name = ? WHERE id = ?', ['Concurrent routing tag', $tag->getId()]);
        $client->jsonRequest('POST', self::URL.'/'.$draft['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $count->value, 'A stale snapshot must be rejected before any write controller dispatch.');
        self::assertSame('Concurrent routing tag', $em->getConnection()->fetchOne('SELECT name FROM common_tag WHERE id = ?', [$tag->getId()]));
    }

    public function testDeleteUsesTheStandardController204BodylessPath(): void
    {
        [$client, $count] = $this->countingClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $tag = new Tag('Delete routing tag', 'routing-delete-tag');
        $em->persist($tag);
        $em->flush();
        $id = $tag->getId();
        $draft = $this->post($client, ['entity' => 'Tag', 'action' => 'delete', 'identifier' => (string) $id, 'changes' => []]);
        self::assertSame(0, $count->value);

        $client->jsonRequest('POST', self::URL.'/'.$draft['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $count->value);
        self::assertSame(204, end($count->statuses), 'The standard delete controller must return its bodyless 204 response.');
        self::assertSame('', end($count->contents));
        self::assertSame(0, (int) $client->getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM common_tag WHERE id = ?', [$id]));
    }

    private function manifestJson(): string
    {
        $entities = [];
        foreach ([
            'Tag' => ['name', 'slug', 'color'],
            'Counter' => ['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled'],
        ] as $name => $fields) {
            $actions = [];
            foreach (['create', 'update'] as $action) {
                $actions[$action] = [
                    'configured' => true,
                    'fields' => array_map(static fn (string $property): array => ['property' => $property], $fields),
                ];
            }
            $actions['delete'] = ['configured' => true, 'fields' => []];
            $entities[] = ['key' => $name, 'name' => $name, 'actions' => $actions];
        }
        $payload = [
            'schemaVersion' => AdminConfigurationPolicy::SCHEMA_VERSION,
            'source' => AdminConfigurationPolicy::SOURCE,
            'entities' => $entities,
            'semanticCapabilities' => [],
        ];
        $revision = hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return json_encode(
            ['revision' => $revision, ...$payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);

        return $value;
    }

    /**
     * @return array{0: KernelBrowser, 1: \stdClass}
     */
    private function countingClient(): array
    {
        // A single kernel keeps the test event listener attached across requests.
        $client = self::createAuthenticatedClient();
        $client->disableReboot();
        $count = (object) ['value' => 0, 'statuses' => [], 'contents' => []];
        $client->getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::CONTROLLER,
            static function (ControllerEvent $event) use ($count): void {
                if ($event->isMainRequest()) {
                    return;
                }
                $controller = $event->getController();
                $instance = is_array($controller) ? ($controller[0] ?? null) : $controller;
                if ($instance instanceof TagController || $instance instanceof CounterController) {
                    ++$count->value;
                }
            },
        );
        $client->getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::RESPONSE,
            static function (ResponseEvent $event) use ($count): void {
                if ($event->isMainRequest()) {
                    return;
                }
                if (preg_match('#^/api/v1/manage/(?:tags|counters)#', $event->getRequest()->getPathInfo()) !== 1) {
                    return;
                }
                $count->statuses[] = $event->getResponse()->getStatusCode();
                $count->contents[] = (string) $event->getResponse()->getContent();
            },
        );

        return [$client, $count];
    }

    private function post(KernelBrowser $client, array $payload): array
    {
        $client->jsonRequest('POST', self::URL, $payload);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $body['data'] ?? $body;
    }

    private function commit(KernelBrowser $client, string $operationId): array
    {
        $client->jsonRequest('POST', self::URL.'/'.$operationId.'/commit', ['confirmed' => true]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $body['data'] ?? $body;
    }
}
