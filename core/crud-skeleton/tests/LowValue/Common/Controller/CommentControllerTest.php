<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common\Controller;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Controller\App\CommentController;
use App\Common\Entity\Comment;
use App\Common\Service\CommentServiceInterface;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the remaining uncovered line (50) of the App CommentController:
 * the non-User branch of defaultCreateValues().
 */
#[Group('low-value')]
final class CommentControllerTest extends TestCase
{
    public function testCreateWithAuthenticatedUserRecordsAuthorFields(): void
    {
        $service = $this->serviceReturningEntity();
        $user = new User();
        $user->setEmail('author@example.com');
        $user->setUsername('author-name');

        $request = $this->request();
        $response = $this->controller($service, $user, $request)->createAction($request);
        self::assertSame(201, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['code']);
        self::assertSame([
            'body' => 'hello',
            'entityType' => 'Page',
            'entityId' => 1,
            'status' => 'pending',
            'author' => $user->getId(),
            'authorName' => 'author-name',
            'authorEmail' => 'author@example.com',
        ], $service->lastUpdateData());
    }

    public function testCreateWithoutUserFallsBackToPendingStatus(): void
    {
        $service = $this->serviceReturningEntity();

        // No user => getUser() returns null => the non-User branch of defaultCreateValues().
        $request = $this->request();
        $response = $this->controller($service, null, $request)->createAction($request);
        self::assertSame(201, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['code']);
        self::assertSame('pending', $data['data']['status']);
        self::assertSame('hello', $data['data']['body']);
    }

    private function serviceReturningEntity(): FakeCommentService
    {
        return new FakeCommentService(new Comment('hello', 'Page', 1));
    }

    private function controller(CommentServiceInterface $service, ?User $user, Request $request): CommentController
    {
        $controller = new class($service, $user) extends CommentController {
            private ?User $currentUser;

            public function __construct(CommentServiceInterface $service, ?User $user)
            {
                parent::__construct($service);
                $this->currentUser = $user;
            }

            protected function getUser(): ?\Symfony\Component\Security\Core\User\UserInterface
            {
                return $this->currentUser;
            }
        };

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $controller->setSerializer($serializer);
        $controller->setTranslator($translator);

        $stack = new RequestStack();
        $stack->push($request);
        $controller->setRequestStack($stack);

        return $controller;
    }

    private function request(): Request
    {
        return new Request([], [], [], [], [], [], json_encode([
            'body' => 'hello',
            'entityType' => 'Page',
            'entityId' => 1,
        ], JSON_THROW_ON_ERROR));
    }
}

final class FakeCommentService implements CommentServiceInterface
{
    private ?array $lastData = null;

    public function __construct(private Comment $entity)
    {
    }

    public function get($object, bool $directly = false)
    {
        return null;
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return [];
    }

    public function new()
    {
        return $this->entity;
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        $this->lastData = $data;

        return $object;
    }

    public function remove($object): bool
    {
        return false;
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        return $fn(null);
    }

    public function lastUpdateData(): ?array
    {
        return $this->lastData;
    }
}
