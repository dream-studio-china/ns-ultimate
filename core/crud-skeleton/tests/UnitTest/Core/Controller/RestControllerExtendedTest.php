<?php

namespace App\Tests\UnitTest\Core\Controller;

use App\Core\Controller\RestController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\Common\Collections\ArrayCollection;

final class RestControllerExtendedTest extends TestCase
{
    private function createController(Request $request, ?SerializerInterface $serializer = null, ?TranslatorInterface $translator = null): RestController
    {
        $stack = new RequestStack();
        $stack->push($request);

        $serializer ??= $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data, $format) => json_encode($data));

        $translator ??= $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new class ($stack, $serializer, $translator) extends RestController {
            public function __construct(RequestStack $rs, SerializerInterface $s, TranslatorInterface $t)
            {
                parent::__construct($rs, $s, $t);
            }
            public function publicSuccess($content = '', string $msg = 'SUCCESS', int $status = 200) {
                return $this->success($content, $msg, $status);
            }
            public function publicWarning(string $msg = 'ERROR', int $code = -1, $raw = '', int $status = 200) {
                return $this->warning($msg, $code, $raw, $status);
            }
        };
    }

    // -------------------------------------------------------
    //  success() edge cases
    // -------------------------------------------------------

    public function testSuccessWith204Status(): void
    {
        $request = Request::create('/', 'GET');
        $controller = $this->createController($request);

        $resp = $controller->publicSuccess('', 'DELETED', 204);
        self::assertSame(204, $resp->getStatusCode());
        self::assertSame('', $resp->getContent());
    }

    public function testSuccessWithArrayCollection(): void
    {
        $request = Request::create('/?page=1&limit=2', 'GET');
        $controller = $this->createController($request);

        $collection = new ArrayCollection([1, 2, 3, 4, 5]);
        $resp = $controller->publicSuccess($collection, 'OK');

        $decoded = json_decode($resp->getContent(), true);
        self::assertCount(2, $decoded['data']); // page 1, limit 2
        self::assertArrayHasKey('paginator', $decoded);
        self::assertSame(5, $decoded['paginator']['total']);
        self::assertSame(3, $decoded['paginator']['pages']);
    }

    public function testSuccessWithPostRequestNoPagination(): void
    {
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json']);
        $controller = $this->createController($request);

        $data = [1, 2, 3];
        $resp = $controller->publicSuccess($data, 'OK');

        $decoded = json_decode($resp->getContent(), true);
        self::assertCount(3, $decoded['data']);
        self::assertArrayNotHasKey('paginator', $decoded);
    }

    // -------------------------------------------------------
    //  warning() edge cases
    // -------------------------------------------------------

    public function testWarningWithCustomStatus(): void
    {
        $request = Request::create('/', 'GET');
        $controller = $this->createController($request);

        $resp = $controller->publicWarning('Not found', 404, '', 404);
        self::assertSame(404, $resp->getStatusCode());

        $decoded = json_decode($resp->getContent(), true);
        self::assertSame(404, $decoded['code']);
        self::assertSame('Not found', $decoded['message']);
    }

    public function testWarningDefaultMessage(): void
    {
        $request = Request::create('/', 'GET');
        $controller = $this->createController($request);

        $resp = $controller->publicWarning();
        $decoded = json_decode($resp->getContent(), true);

        self::assertSame(-1, $decoded['code']);
    }

    public function testWarningWithRawData(): void
    {
        $request = Request::create('/', 'GET');
        $controller = $this->createController($request);

        $resp = $controller->publicWarning('Error', 400, ['field' => 'name']);
        $decoded = json_decode($resp->getContent(), true);

        self::assertSame(['field' => 'name'], $decoded['raw_data']);
    }

    // -------------------------------------------------------
    //  Pagination edge cases
    // -------------------------------------------------------

    public function testPaginationWithLargePageNumber(): void
    {
        $request = Request::create('/?page=10&limit=5', 'GET');
        $controller = $this->createController($request);

        $data = [1, 2, 3]; // only 3 items total
        $resp = $controller->publicSuccess($data, 'OK');

        $decoded = json_decode($resp->getContent(), true);
        self::assertCount(0, $decoded['data']); // page 10 out of range
        self::assertSame(3, $decoded['paginator']['total']);
        self::assertSame(1, $decoded['paginator']['pages']);
    }

    public function testPaginationWithSingleItem(): void
    {
        $request = Request::create('/?page=1&limit=10', 'GET');
        $controller = $this->createController($request);

        $data = ['only'];
        $resp = $controller->publicSuccess($data, 'OK');

        $decoded = json_decode($resp->getContent(), true);
        self::assertCount(1, $decoded['data']);
        self::assertSame(1, $decoded['paginator']['pages']);
        self::assertFalse($decoded['paginator']['has_previous']);
        self::assertFalse($decoded['paginator']['has_next']);
    }

    public function testPaginationSecondPage(): void
    {
        $request = Request::create('/?page=2&limit=3', 'GET');
        $controller = $this->createController($request);

        $data = [1, 2, 3, 4, 5, 6, 7, 8];
        $resp = $controller->publicSuccess($data, 'OK');

        $decoded = json_decode($resp->getContent(), true);
        self::assertCount(3, $decoded['data']); // items 4,5,6
        self::assertTrue($decoded['paginator']['has_previous']);
        self::assertTrue($decoded['paginator']['has_next']);
    }
}
