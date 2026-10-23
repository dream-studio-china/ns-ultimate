<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use NsUltimate\Apps\AdminAgent\Backend\Contract\AdminCrudExecutor;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Narrow, server-bound adapter from agent mutations to the standard administration
 * controllers. The caller can select only a fixed entity/action pair; routes,
 * controllers, HTTP verbs and query parameters are never accepted from proposals.
 *
 * A kernel SUB_REQUEST is used so the ordinary controller, its serializer/validation
 * hooks and its class-level ROLE_ADMIN IsGranted check run. The security firewall
 * intentionally ignores sub-requests, so this class independently requires the
 * current authenticated token to grant ROLE_ADMIN and explicitly audits the bound
 * controller's class-level attribute before dispatch.
 */
final class AdminCrudControllerBridge implements AdminCrudExecutor
{
    /**
     * Fixed bindings to the effective router configuration. No binding is inferred
     * from Doctrine metadata, request attributes or caller-provided class names.
     *
     * @var array<string, array<string, array{route: string, controller: class-string, action: string, method: string, path: string}>>
     */
    private const BINDINGS = [
        'Tag' => [
            'create' => [
                'route' => 'manage-tags-create',
                'controller' => \App\Common\Controller\Manage\TagController::class,
                'action' => 'createAction',
                'method' => 'POST',
                'path' => '/api/v1/manage/tags',
            ],
            'update' => [
                'route' => 'manage-tags-update',
                'controller' => \App\Common\Controller\Manage\TagController::class,
                'action' => 'updateAction',
                'method' => 'PUT',
                'path' => '/api/v1/manage/tags/{id}',
            ],
            'delete' => [
                'route' => 'manage-tags-delete',
                'controller' => \App\Common\Controller\Manage\TagController::class,
                'action' => 'deleteAction',
                'method' => 'DELETE',
                'path' => '/api/v1/manage/tags/{id}',
            ],
        ],
        'Counter' => [
            'create' => [
                'route' => 'api-v1-business-counters-create',
                'controller' => \NsUltimate\Business\Counter\Controller\Admin\CounterController::class,
                'action' => 'createAction',
                'method' => 'POST',
                'path' => '/api/v1/manage/counters',
            ],
            'update' => [
                'route' => 'api-v1-business-counters-update',
                'controller' => \NsUltimate\Business\Counter\Controller\Admin\CounterController::class,
                'action' => 'updateAction',
                'method' => 'PUT',
                'path' => '/api/v1/manage/counters/{id}',
            ],
            'delete' => [
                'route' => 'api-v1-business-counters-delete',
                'controller' => \NsUltimate\Business\Counter\Controller\Admin\CounterController::class,
                'action' => 'deleteAction',
                'method' => 'DELETE',
                'path' => '/api/v1/manage/counters/{id}',
            ],
        ],
    ];

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ManagerRegistry $doctrine,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Opt-in CLI fixture/harness bootstrap: install the same trusted main-request
     * context and explicit administrator token that a normal admin request carries.
     * Not available to HTTP execution.
     *
     * @internal
     */
    public function installHarnessContext(Request $mainRequest, UserInterface $administrator): void
    {
        if ('cli' !== PHP_SAPI) {
            throw new \LogicException('The harness context is only available to CLI fixtures.');
        }
        if ($this->requestStack->getMainRequest() === null) {
            $this->requestStack->push($mainRequest);
        }
        $this->tokenStorage->setToken(new UsernamePasswordToken($administrator, 'main', $administrator->getRoles()));
    }

    public function execute(
        EntityManagerInterface $manager,
        string $entityKey,
        string $action,
        ?string $identifier,
        array $changes,
    ): ?string {
        $binding = $this->binding($entityKey, $action);
        $this->assertArguments($action, $identifier, $changes);
        $this->authorizeAdministrator();
        $this->assertSameManager($manager);
        $this->assertTrustedBinding($binding);

        $mainRequest = $this->requestStack->getMainRequest();
        if (!$mainRequest instanceof Request) {
            throw new \LogicException('A trusted main request is required to invoke the standard administration controller.');
        }

        $path = $identifier === null
            ? $binding['path']
            : str_replace('{id}', rawurlencode($identifier), $binding['path']);
        $body = $action === 'delete' ? '' : json_encode($changes, JSON_THROW_ON_ERROR);
        // The sub-request is built only from fixed binding data and trusted main
        // request scheme/host/port. No outer attributes, query, cookies or headers
        // are copied, so @transform/@basis/@mode/@partial cannot be injected.
        $subRequest = Request::create(
            $mainRequest->getSchemeAndHttpHost().$path,
            $binding['method'],
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
        if ($subRequest->query->count() !== 0 || $subRequest->attributes->count() !== 0) {
            throw new \LogicException('The standard administration sub-request was not clean.');
        }

        $connection = $manager->getConnection();
        $levelBefore = $connection->getTransactionNestingLevel();
        $activeBefore = $connection->isTransactionActive();
        $token = $this->tokenStorage->getToken();
        $registryManagerBefore = $this->doctrine->getManager();
        $unitOfWorkBefore = $manager->getUnitOfWork();

        // Validate the route/controller that the kernel actually selected for this
        // exact sub-request immediately before controller execution. Checking the
        // named route in the collection is not enough: a higher-priority shadow
        // route can share the same path and dispatch a different controller.
        $routeListener = function (ControllerEvent|ControllerArgumentsEvent $event) use ($subRequest, $binding, $action, $identifier): void {
            if ($event->getRequest() !== $subRequest) {
                return;
            }
            $this->assertActualRoute($event, $binding, $action, $identifier);
        };
        $this->dispatcher->addListener(KernelEvents::CONTROLLER, $routeListener, -2048);
        $this->dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, $routeListener, -2048);

        try {
            $response = $this->kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
        } catch (AccessDeniedException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ProposalConflictException('The standard administration controller rejected the stored proposal. Prepare a new preview.', $error);
        } finally {
            $this->dispatcher->removeListener(KernelEvents::CONTROLLER, $routeListener);
            $this->dispatcher->removeListener(KernelEvents::CONTROLLER_ARGUMENTS, $routeListener);
            // The firewall does not run on sub-requests; keep the same authenticated
            // token and never leak a controller's token mutation into the outer request.
            if ($this->tokenStorage->getToken() !== $token) {
                $this->tokenStorage->setToken($token);
            }
        }

        $managerAborted = !$manager->isOpen()
            || $registryManagerBefore !== $this->doctrine->getManager()
            || $unitOfWorkBefore !== $manager->getUnitOfWork()
            || $connection !== $manager->getConnection();
        $boundaryAborted = $connection->getTransactionNestingLevel() !== $levelBefore
            || $connection->isTransactionActive() !== $activeBefore;

        // A definitive controller rejection (HTTP/warning envelope) remains a
        // proposal conflict even when the controller reset its manager after a
        // failed flush; the outer transaction still rolls back. A successful
        // envelope must never be accepted after a manager/boundary reset.
        try {
            $identifier = $this->readEnvelope($action, $identifier, $response);
        } catch (ProposalConflictException $error) {
            throw $error;
        }
        if ($managerAborted || $boundaryAborted) {
            throw new \RuntimeException('The standard administration controller reset the operation context; the proposal was aborted.');
        }

        return $identifier;
    }

    /**
     * @return array{route: string, controller: class-string, action: string, method: string, path: string}
     */
    private function binding(string $entityKey, string $action): array
    {
        $binding = self::BINDINGS[$entityKey][$action] ?? null;
        if (!is_array($binding)) {
            throw new \LogicException('No trusted standard administration binding exists for this entity and action.');
        }

        return $binding;
    }

    /** @param array<string, mixed> $changes */
    private function assertArguments(string $action, ?string $identifier, array $changes): void
    {
        if ($action === 'create') {
            if ($identifier !== null) {
                throw new \LogicException('A standard administration create cannot target an identifier.');
            }
        } elseif ($identifier === null
            || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD', $identifier)) {
            throw new \LogicException('The standard administration target identifier is malformed.');
        }
        if ($action === 'delete') {
            if ($changes !== []) {
                throw new \LogicException('A standard administration delete cannot include fields.');
            }

            return;
        }
        if ($changes === [] || array_is_list($changes)) {
            throw new \LogicException('A standard administration write requires a field object.');
        }
    }

    private function authorizeAdministrator(): void
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null || !$token->getUser() instanceof UserInterface) {
            throw new AccessDeniedException('An authenticated administrator session is required.');
        }
        if (!$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('A fully authenticated administrator session is required.');
        }
        if (!$this->authorization->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Standard administration requires ROLE_ADMIN.');
        }
    }

    private function assertSameManager(EntityManagerInterface $manager): void
    {
        if ($this->doctrine->getManager() !== $manager) {
            throw new \LogicException('The standard administration bridge must use the active operation entity manager.');
        }
    }

    /**
     * Default-closed audit: the named route, verb, path and controller/action must
     * still match the fixed binding, and the controller must still declare a
     * class-level ROLE_ADMIN IsGranted attribute.
     *
     * @param array{route: string, controller: class-string, action: string, method: string, path: string} $binding
     */
    private function assertTrustedBinding(array $binding): void
    {
        $route = $this->router->getRouteCollection()->get($binding['route']);
        if ($route === null
            || $route->getPath() !== $binding['path']
            || !in_array($binding['method'], $route->getMethods(), true)
            || $route->getDefault('_controller') !== $binding['controller'].'::'.$binding['action']) {
            throw new \LogicException('The standard administration route no longer matches the audited binding.');
        }

        $reflection = new \ReflectionClass($binding['controller']);
        $authorized = false;
        foreach ($reflection->getAttributes(IsGranted::class) as $attribute) {
            if ($attribute->newInstance()->attribute === 'ROLE_ADMIN') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            throw new \LogicException('The standard administration controller no longer declares class-level ROLE_ADMIN.');
        }
    }

    /**
     * Verify the kernel-selected route and resolved controller for this exact
     * sub-request. This catches same-path shadow routes and controller aliases
     * that the named-route collection check cannot see.
     *
     * @param array{route: string, controller: class-string, action: string, method: string, path: string} $binding
     */
    private function assertActualRoute(
        ControllerEvent|ControllerArgumentsEvent $event,
        array $binding,
        string $action,
        ?string $identifier,
    ): void {
        $request = $event->getRequest();
        $expectedController = $binding['controller'].'::'.$binding['action'];
        if ($request->attributes->get('_route') !== $binding['route']
            || $request->attributes->get('_controller') !== $expectedController) {
            throw new \LogicException('The actual administration route does not match the audited binding.');
        }

        $controller = $event->getController();
        if (!is_array($controller)
            || count($controller) !== 2
            || !is_object($controller[0])
            || get_class($controller[0]) !== $binding['controller']
            || $controller[1] !== $binding['action']) {
            throw new \LogicException('The actual administration controller does not match the audited binding.');
        }

        if ($action !== 'create') {
            $parameters = $request->attributes->get('_route_params');
            $selected = is_array($parameters) ? ($parameters['id'] ?? null) : null;
            if (!is_string($selected) || $selected !== (string) $identifier) {
                throw new \LogicException('The actual administration target does not match the audited binding.');
            }
        }
    }

    private function readEnvelope(string $action, ?string $identifier, Response $response): ?string
    {
        $status = $response->getStatusCode();
        if ($action === 'delete') {
            if ($status !== 204 || $response->getContent() !== '') {
                throw new ProposalConflictException('The standard administration delete did not complete. Prepare a new preview.');
            }

            return null;
        }

        if ($status !== 200 && $status !== 201) {
            throw new ProposalConflictException('The standard administration controller rejected the stored proposal. Prepare a new preview.');
        }

        $encoded = $response->getContent();
        if (!is_string($encoded) || trim($encoded) === '') {
            throw new \RuntimeException('The standard administration controller returned an empty response body.');
        }
        try {
            $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('The standard administration controller returned malformed JSON.', 0, $error);
        }
        if (!is_array($payload) || !array_key_exists('code', $payload) || $payload['code'] !== 0) {
            // warning() defaults to HTTP 200 with a non-zero envelope code.
            throw new ProposalConflictException('The standard administration controller reported an error. Prepare a new preview.');
        }

        $entity = $payload['data'] ?? null;
        if (!is_array($entity) || !array_key_exists('id', $entity)) {
            throw new \RuntimeException('The standard administration controller returned no persisted identifier.');
        }
        $persisted = $entity['id'];
        if (is_int($persisted)) {
            $persisted = (string) $persisted;
        }
        if (!is_string($persisted) || !preg_match('/^[1-9][0-9]{0,14}$/D', $persisted)) {
            throw new \RuntimeException('The standard administration controller returned a malformed persisted identifier.');
        }
        if ($identifier !== null && ctype_digit($identifier) && $identifier !== $persisted) {
            throw new \RuntimeException('The standard administration controller persisted a different target.');
        }

        return $persisted;
    }
}
