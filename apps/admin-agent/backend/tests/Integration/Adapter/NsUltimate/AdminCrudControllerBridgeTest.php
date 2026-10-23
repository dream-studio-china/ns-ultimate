<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Tag;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CounterMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagBatchCreateAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\TagMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AdminConfigurationPolicy;
use NsUltimate\Apps\AdminAgent\Backend\Service\AdminCrudControllerBridge;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchAgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\BatchCreateAdapterRegistry;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Business\Counter\Service\CounterWriteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exercises the audited bridge directly with the real standard controller and the
 * real composed container. HTTP-specific routing/idempotency assertions live in
 * AdminCrudControllerRoutingTest.
 */
final class AdminCrudControllerBridgeTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const MAIN_REQUEST = 'http://localhost/api/v1/manage/agent/operations';

    private ?string $manifestPath = null;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        // A previous failing commit may have closed the shared manager.
        self::getContainer()->get('doctrine')->resetManager();
    }

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null) {
            @unlink($this->manifestPath);
            $this->manifestPath = null;
        }
        parent::tearDown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testUnsupportedBindingsFailClosedAndNonAdminSessionsAreDenied(): void
    {
        $container = self::getContainer();
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $bridge = $container->get(AdminCrudControllerBridge::class);

        foreach ([
            ['Article', 'create'],
            ['Tag', 'approve'],
            ['Tag', 'batch-update'],
            ['Counter', 'restore'],
        ] as [$entity, $action]) {
            try {
                $bridge->execute($em, $entity, $action, null, []);
                self::fail('An unbound entity/action must fail closed.');
            } catch (\LogicException) {
            }
        }

        $bridge->installHarnessContext(
            Request::create(self::MAIN_REQUEST, 'POST'),
            (new User())->setEmail('bridge-non-admin@example.test')->setUsername('bridge-non-admin')->setRoles(['ROLE_USER']),
        );
        try {
            $bridge->execute($em, 'Tag', 'create', null, ['name' => 'Denied', 'slug' => 'bridge-denied']);
            self::fail('A non-admin token must not reach the standard controller.');
        } catch (AccessDeniedException) {
        }
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-denied']));
    }

    public function testMalformedIdentifiersAndDeleteFieldsAreRejectedBeforeDispatch(): void
    {
        [, , $bridge] = $this->administratorContext();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $rejected = 0;

        foreach (['1garbage', '0', str_repeat('a', 129), '../1'] as $identifier) {
            try {
                $bridge->execute($em, 'Tag', 'update', $identifier, ['name' => 'No']);
                self::fail('A malformed identifier must be rejected.');
            } catch (\LogicException) {
                ++$rejected;
            }
        }
        try {
            $bridge->execute($em, 'Tag', 'delete', '1', ['name' => 'No']);
            self::fail('Delete fields must be rejected.');
        } catch (\LogicException) {
            ++$rejected;
        }
        self::assertSame(5, $rejected);
    }

    public function testRealControllerRejectsInvalidInputWithoutWriting(): void
    {        [, , $bridge] = $this->administratorContext();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        try {
            // The standard Tag controller requires slug; the agent adapter normally
            // prevents this, so a direct bridge call proves the controller hook ran.
            $bridge->execute($em, 'Tag', 'create', null, ['name' => 'Missing slug']);
            self::fail('The standard controller must reject a missing required property.');
        } catch (ProposalConflictException) {
        }
        self::assertSame(0, $em->getRepository(Tag::class)->count(['name' => 'Missing slug']));
    }

    public function testTamperedRouterBindingFailsClosedWithoutWriting(): void
    {
        [, , $bridge] = $this->administratorContext();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $router = self::getContainer()->get('router');
        $route = $router->getRouteCollection()->get('manage-tags-create');
        self::assertNotNull($route);
        $original = $route->getDefault('_controller');
        $route->setDefault('_controller', \App\Common\Controller\Manage\TagController::class.'::deleteAction');
        try {
            try {
                $bridge->execute($em, 'Tag', 'create', null, ['name' => 'Tampered binding', 'slug' => 'bridge-tampered-binding']);
                self::fail('A route whose controller drifted from the audited binding must fail closed.');
            } catch (\LogicException) {
            }
        } finally {
            $route->setDefault('_controller', $original);
        }
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-tampered-binding']));
    }

    public function testHttp200WarningAndMalformedResponseAbortTheOuterTransaction(): void
    {
        $container = self::getContainer();
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $warningBridge = $this->fakeResponseBridge(new Response('{"code":-1,"message":"warning"}', 200));
        $warningBridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());

        try {
            $em->wrapInTransaction(function (EntityManagerInterface $manager) use ($warningBridge): void {
                $manager->persist(new Tag('Warning tag', 'bridge-warning-tag'));
                $warningBridge->execute($manager, 'Tag', 'create', null, ['name' => 'Warning tag', 'slug' => 'bridge-warning-tag']);
            });
            self::fail('A warning envelope must throw instead of recording a successful write.');
        } catch (ProposalConflictException) {
        }
        self::assertFalse($em->isOpen(), 'A rejected bridge call must abort the operation manager.');
        $em = $doctrine->resetManager();
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-warning-tag']));

        $malformedBridge = $this->fakeResponseBridge(new Response('not-json', 201));
        $malformedBridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());
        try {
            $em->wrapInTransaction(function (EntityManagerInterface $manager) use ($malformedBridge): void {
                $manager->persist(new Tag('Malformed tag', 'bridge-malformed-tag'));
                $malformedBridge->execute($manager, 'Tag', 'create', null, ['name' => 'Malformed tag', 'slug' => 'bridge-malformed-tag']);
            });
            self::fail('A malformed controller response must abort the transaction.');
        } catch (\RuntimeException) {
        }
        self::assertFalse($em->isOpen());
        $em = $doctrine->resetManager();
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-malformed-tag']));
    }

    public function testReadbackMismatchRollsBackTargetAndKeepsTheReceiptPending(): void
    {
        [$service, , $bridge] = $this->administratorContext();
        $doctrine = self::getContainer()->get('doctrine');
        $em = $doctrine->getManager();
        $connection = $em->getConnection();
        $connection->executeStatement(
            "CREATE TRIGGER test_tag_tamper AFTER INSERT ON common_tag BEGIN UPDATE common_tag SET name = 'Tampered' WHERE id = NEW.id; END",
        );
        try {
            $preview = $service->prepare('bridge-actor', [
                'entity' => 'Tag', 'action' => 'create', 'changes' => ['name' => 'Readback tag', 'slug' => 'bridge-readback-tag'],
            ]);
            try {
                $service->commit('bridge-actor', $preview['operationId']);
                self::fail('A read-back mismatch must abort the commit.');
            } catch (\RuntimeException) {
            }
            self::assertFalse($em->isOpen());
        } finally {
            $em = $doctrine->resetManager();
            $em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_tag_tamper');
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_tag WHERE slug = ?', ['bridge-readback-tag']));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])?->getStatus());
    }

    public function testReceiptFlushFailureRollsBackTheTargetAndKeepsTheReceiptPending(): void
    {
        [$service, , $bridge] = $this->administratorContext();
        $doctrine = self::getContainer()->get('doctrine');
        $em = $doctrine->getManager();
        $em->getConnection()->executeStatement(
            "CREATE TRIGGER test_receipt_block BEFORE UPDATE ON admin_agent_operation WHEN NEW.status = 'succeeded' BEGIN SELECT RAISE(ABORT, 'receipt blocked'); END",
        );
        try {
            $preview = $service->prepare('bridge-actor', [
                'entity' => 'Tag', 'action' => 'create', 'changes' => ['name' => 'Receipt tag', 'slug' => 'bridge-receipt-tag'],
            ]);
            try {
                $service->commit('bridge-actor', $preview['operationId']);
                self::fail('A receipt flush failure must abort the commit.');
            } catch (\Throwable) {
            }
            self::assertFalse($em->isOpen());
        } finally {
            $em = $doctrine->resetManager();
            $em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_receipt_block');
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_tag WHERE slug = ?', ['bridge-receipt-tag']));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])?->getStatus());
    }

    public function testSecondBatchRowFailureRollsBackEveryRowAndTheReceipt(): void
    {
        [, $batch, $bridge] = $this->administratorContext();
        $doctrine = self::getContainer()->get('doctrine');
        $em = $doctrine->getManager();
        $rows = [
            ['name' => 'Batch duplicate name', 'slug' => 'bridge-batch-one'],
            ['name' => 'Batch duplicate name', 'slug' => 'bridge-batch-two'],
            ['name' => 'Batch unique name', 'slug' => 'bridge-batch-three'],
        ];
        $preview = $batch->prepare('bridge-actor', ['entity' => 'Tag', 'action' => 'create', 'rows' => $rows]);
        $em->getConnection()->executeStatement('CREATE UNIQUE INDEX test_batch_tag_name ON common_tag (name)');
        try {
            try {
                $batch->commit('bridge-actor', $preview['operationId']);
                self::fail('A second-row controller failure must roll back the whole batch.');
            } catch (ProposalConflictException) {
            }
            self::assertFalse($em->isOpen());
        } finally {
            $em = $doctrine->resetManager();
            $em->getConnection()->executeStatement('DROP INDEX IF EXISTS test_batch_tag_name');
        }

        foreach ($rows as $row) {
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_tag WHERE slug = ?', [$row['slug']]));
        }
        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertInstanceOf(AgentBatchOperation::class, $operation);
        self::assertSame('pending', $operation->getStatus());
        self::assertCount(3, $operation->getRows());
    }

    public function testDeletedTagReinsertedByIdIsNotVerifiedAndRollsBack(): void
    {
        [$service] = $this->administratorContext();
        $doctrine = self::getContainer()->get('doctrine');
        $em = $doctrine->getManager();
        $tag = new Tag('Reinsert tag', 'bridge-reinsert-tag');
        $em->persist($tag);
        $em->flush();
        $id = (string) $tag->getId();
        $preview = $service->prepare('bridge-actor', [
            'entity' => 'Tag', 'action' => 'delete', 'identifier' => $id, 'changes' => [],
        ]);
        $em->getConnection()->executeStatement(
            "CREATE TRIGGER test_tag_reinsert AFTER DELETE ON common_tag BEGIN "
            ."INSERT INTO common_tag (id, uuid, name, slug, color, created_at, updated_at) "
            ."VALUES (OLD.id, OLD.uuid, OLD.name, OLD.slug, OLD.color, OLD.created_at, OLD.updated_at); END",
        );
        try {
            try {
                $service->commit('bridge-actor', $preview['operationId']);
                self::fail('A reinserted Tag row must fail read-back verification.');
            } catch (\RuntimeException) {
            }
            self::assertFalse($em->isOpen());
        } finally {
            $em = $doctrine->resetManager();
            $em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_tag_reinsert');
        }

        // The outer rollback restores the original row and must not leave a succeeded receipt.
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM common_tag WHERE id = ?', [$id]));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])?->getStatus());
    }

    public function testDeletedCounterReinsertedByIdIsNotVerifiedAndRollsBack(): void
    {
        [$service] = $this->administratorContext();
        $doctrine = self::getContainer()->get('doctrine');
        $em = $doctrine->getManager();
        $counter = (new \NsUltimate\Business\Counter\Entity\Counter())
            ->setStoreUuid('00000000-0000-4000-8000-000000000001')
            ->setCode('bridge-reinsert')
            ->setName('Reinsert counter');
        $em->persist($counter);
        $em->flush();
        $id = (string) $counter->getId();
        $preview = $service->prepare('bridge-actor', [
            'entity' => 'Counter', 'action' => 'delete', 'identifier' => $id, 'changes' => [],
        ]);
        $em->getConnection()->executeStatement(
            "CREATE TRIGGER test_counter_reinsert AFTER DELETE ON counter BEGIN "
            ."INSERT INTO counter (id, uuid, store_uuid, code, name, capacity, sort, metadata, enabled, created_at, updated_at) "
            ."VALUES (OLD.id, OLD.uuid, OLD.store_uuid, OLD.code, OLD.name, OLD.capacity, OLD.sort, OLD.metadata, OLD.enabled, OLD.created_at, OLD.updated_at); END",
        );
        try {
            try {
                $service->commit('bridge-actor', $preview['operationId']);
                self::fail('A reinserted Counter row must fail read-back verification.');
            } catch (\RuntimeException) {
            }
            self::assertFalse($em->isOpen());
        } finally {
            $em = $doctrine->resetManager();
            $em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_counter_reinsert');
        }

        // The outer rollback restores the original row and must not leave a succeeded receipt.
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM counter WHERE id = ?', [$id]));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])?->getStatus());
    }

    public function testManagerResetDuringSuccessfulDispatchAborts(): void
    {
        $container = self::getContainer();
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $kernel = new class($doctrine) implements HttpKernelInterface {
            public function __construct(private readonly \Doctrine\Persistence\ManagerRegistry $doctrine) {}
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                $this->doctrine->resetManager();
                return new Response('{"code":0,"data":{"id":"1"}}', 201);
            }
        };
        $bridge = $this->bridgeWithKernel($kernel);
        $bridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());

        try {
            $bridge->execute($em, 'Tag', 'create', null, ['name' => 'Reset tag', 'slug' => 'bridge-reset-tag']);
            self::fail('An in-place manager reset during a successful dispatch must abort.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('reset the operation context', $error->getMessage());
        }
        $em = $doctrine->resetManager();
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-reset-tag']));
    }

    public function testRememberMeAdministratorIsRejectedBeforeDispatch(): void
    {
        $container = self::getContainer();
        $spy = new class implements HttpKernelInterface {
            public int $calls = 0;
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                ++$this->calls;
                return new Response('{"code":0,"data":{"id":"1"}}', 201);
            }
        };
        $bridge = $this->bridgeWithKernel($spy);
        $bridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());
        $container->get('security.token_storage')->setToken(new RememberMeToken($this->administrator(), 'main'));

        try {
            $bridge->execute($container->get(EntityManagerInterface::class), 'Tag', 'create', null, [
                'name' => 'Remembered tag', 'slug' => 'bridge-remembered-tag',
            ]);
            self::fail('A remember-me token must not pass the bridge authorization gate.');
        } catch (AccessDeniedException) {
        }
        self::assertSame(0, $spy->calls);
    }

    public function testShadowRouteWithDifferentControllerIsRejectedBeforeInvocation(): void
    {
        $spy = new class {
            public int $calls = 0;
            public function createAction(): Response
            {
                ++$this->calls;
                return new Response('{"code":0,"data":{"id":"1"}}', 201);
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            $event->getRequest()->attributes->set('_route', 'shadow-tag-create');
            $event->getRequest()->attributes->set('_controller', 'ShadowController::createAction');
            $event->getRequest()->attributes->set('_route_params', []);
        });
        $resolver = new class($spy) implements ControllerResolverInterface {
            public function __construct(private readonly object $controller) {}
            public function getController(Request $request): callable|false
            {
                return [$this->controller, 'createAction'];
            }
        };
        $kernel = new HttpKernel($dispatcher, $resolver, self::getContainer()->get('request_stack'));
        $bridge = $this->bridgeWithKernel($kernel, $dispatcher);
        $bridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());
        $em = self::getContainer()->get(EntityManagerInterface::class);

        try {
            $bridge->execute($em, 'Tag', 'create', null, ['name' => 'Shadow tag', 'slug' => 'bridge-shadow-tag']);
            self::fail('A same-path shadow route must not dispatch a different controller.');
        } catch (ProposalConflictException) {
        }
        self::assertSame(0, $spy->calls);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'bridge-shadow-tag']));
    }

    private function policy(): AdminConfigurationPolicy
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
        $path = tempnam(sys_get_temp_dir(), 'ns-agent-bridge-policy-');
        if ($path === false) throw new \RuntimeException('Unable to create a disposable policy manifest.');
        file_put_contents($path, json_encode(
            ['revision' => $revision, ...$payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $this->manifestPath = $path;

        return new AdminConfigurationPolicy($path);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);

        return $value;
    }

    private function bridgeWithKernel(HttpKernelInterface $kernel, ?\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher = null): AdminCrudControllerBridge
    {
        $container = self::getContainer();

        return new AdminCrudControllerBridge(
            $kernel,
            $container->get('request_stack'),
            $container->get('router'),
            $container->get('security.token_storage'),
            $container->get(AuthorizationCheckerInterface::class),
            $container->get('doctrine'),
            $dispatcher ?? $container->get('event_dispatcher'),
        );
    }

    /**
     * @return array{0: AgentOperationService, 1: BatchAgentOperationService, 2: AdminCrudControllerBridge}
     */
    private function administratorContext(): array
    {
        $container = self::getContainer();
        $bridge = $container->get(AdminCrudControllerBridge::class);
        $bridge->installHarnessContext(Request::create(self::MAIN_REQUEST, 'POST'), $this->administrator());
        $em = $container->get(EntityManagerInterface::class);
        $auth = $container->get(AuthorizationCheckerInterface::class);
        $policy = $this->policy();
        $tag = new TagMutationAdapter($em, $auth, new TagWriteService($em), $bridge);
        $counter = new CounterMutationAdapter($em, $auth, new CounterWriteService($em), $bridge);
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$tag, $counter]), $policy);
        $batch = new BatchAgentOperationService($em, new BatchCreateAdapterRegistry([new TagBatchCreateAdapter($tag)]), $policy);

        return [$service, $batch, $bridge];
    }

    private function fakeResponseBridge(Response $response): AdminCrudControllerBridge
    {
        $kernel = new class($response) implements HttpKernelInterface {
            public function __construct(private readonly Response $response) {}
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return $this->response;
            }
        };

        return $this->bridgeWithKernel($kernel);
    }

    private function administrator(): User
    {
        return (new User())->setEmail('bridge-admin@example.test')->setUsername('bridge-admin')->setRoles(['ROLE_ADMIN']);
    }
}
