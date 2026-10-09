<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller;

use App\Core\Controller\RestController;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RestControllerCoverageTest extends TestCase
{
    private function s(): SerializerInterface
    {
        return new class implements SerializerInterface {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed { return null; }
        };
    }

    private function t(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $p = [], ?string $d = null, ?string $l = null): string { return (string) $id; }
            public function getLocale(): string { return 'en'; }
        };
    }

    private function createController(Request $request): object
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new class($stack, $this->s(), $this->t()) extends RestController {
            public function __construct(RequestStack $s, SerializerInterface $ser, TranslatorInterface $tr) { parent::__construct($s, $ser, $tr); }
            public function publicSuccess(mixed $content = '', string $msg = 'SUCCESS', int $status = 200): Response
            {
                return $this->success($content, $msg, $status);
            }
            public function publicWarning(string $msg = 'error', int $code = -1, mixed $raw = '', int $status = 200): Response
            {
                return $this->warning($msg, $code, $raw, $status);
            }
            public function publicPagination(mixed $collection): array { return $this->pagination($collection); }
        };
    }

    public function testDisplayArrayFieldProjection(): void
    {
        $entity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Alice'; }
            public function getEmail(): string { return 'a@b.com'; }
        };

        $req = Request::create('/api/test', 'GET', ['@display' => '["entity.id", "entity.name"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);
        $d = json_decode((string) $r->getContent(), true);

        self::assertCount(2, $d['data'][0]);
        self::assertSame(1, $d['data'][0]['entity.id']);
        self::assertSame('Alice', $d['data'][0]['entity.name']);
    }

    #[Group('low-value')]
    public function testDisplayExpressionObject(): void
    {
        $entity = new class {
            public function getId(): int { return 99; }
        };

        $req = Request::create('/api/test', 'GET', ['@display' => '{"val":"entity.getId()"}']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);
        $d = json_decode((string) $r->getContent(), true);

        self::assertSame(99, $d['data'][0]['val']);
    }

    #[Group('low-value')]
    public function testDisplayReduceOnArray(): void
    {
        $entity = new class {
            public function getId(): int { return 7; }
            public function __toString(): string { return 'Seven'; }
        };

        $req = Request::create('/api/test', 'GET', ['@display' => 'reduce']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);
        $d = json_decode((string) $r->getContent(), true);

        self::assertSame(7, $d['data'][0]['id']);
        self::assertSame('Seven', $d['data'][0]['__toString']);
    }

    public function testDisplayComplexReturnsFullEntity(): void
    {
        $entity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'X'; }
        };

        $req = Request::create('/api/test', 'GET', ['@display' => 'complex']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);
        $d = json_decode((string) $r->getContent(), true);

        self::assertIsArray($d['data']);
    }

    public function testExpandsOnArray(): void
    {
        $child = new class {
            public ?object $__metadata = null;
            public function getId(): int { return 2; }
        };
        $entity = new class($child) {
            public ?object $__metadata = null;
            public function __construct(private $c) {}
            public function getId(): int { return 1; }
            public function getChild(): object { return $this->c; }
        };

        $req = Request::create('/api/test', 'GET', ['@expands' => '["child"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        self::assertSame(200, $r->getStatusCode());
    }

    public function testExpandsOnSingleEntity(): void
    {
        $entity = new class {
            public ?object $__metadata = null;
            public function getId(): int { return 5; }
            public function getFoo(): object
            {
                return new class {
                    public ?object $__metadata = null;
                    public function getId(): int { return 10; }
                };
            }
        };

        $req = Request::create('/api/test', 'GET', ['@expands' => '["foo"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess($entity);

        self::assertSame(200, $r->getStatusCode());
    }

    #[Group('low-value')]
    public function testPaginationOnGetRequest(): void
    {
        $req = Request::create('/api/test', 'GET', ['page' => 1, 'limit' => 2]);
        $c = $this->createController($req);
        $result = $c->publicPagination([1, 2, 3, 4]);

        self::assertCount(2, $result['items']);
        self::assertArrayHasKey('paginator', $result);
        self::assertNotNull($result['paginator']);
    }

    public function testPaginationOnPostRequest(): void
    {
        $req = Request::create('/api/test', 'POST', [], [], [], [], json_encode(['x' => 1]));
        $c = $this->createController($req);
        $result = $c->publicPagination([1, 2, 3]);

        self::assertSame([1, 2, 3], $result['items']);
        self::assertNull($result['paginator']);
    }

    public function testPaginationWithArrayCollection(): void
    {
        $req = Request::create('/api/test', 'GET', ['page' => 2, 'limit' => 1]);
        $c = $this->createController($req);
        $result = $c->publicPagination(new ArrayCollection([1, 2, 3]));

        self::assertCount(1, $result['items']);
        self::assertArrayHasKey('paginator', $result);
    }

    #[Group('low-value')]
    public function testSuccessWithPostRequestNoPagination(): void
    {
        $req = Request::create('/api/test', 'POST', [], [], [], [], json_encode(['x' => 1]));
        $c = $this->createController($req);
        $r = $c->publicSuccess([1, 2, 3]);

        $d = json_decode((string) $r->getContent(), true);
        self::assertFalse(isset($d['paginator']));
    }

    public function testDisplayWithPlainString(): void
    {
        $entity = new class {
            public function getId(): int { return 1; }
        };

        $req = Request::create('/api/test', 'GET', ['@display' => 'raw']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        self::assertSame(200, $r->getStatusCode());
    }
}
