<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Controller;

use App\Common\Entity\Content;
use App\Core\Controller\RestController;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers RestController::pagination() against a real QueryBuilder/DoctrinePaginator.
 *
 * NOTE: This test boots the Symfony kernel and drops/recreates the shared
 * sqlite schema in var/test.db (see DatabaseBootstrapTrait). Do not run it in
 * parallel with other integration tests that also bootstrap the same database
 * file, otherwise the schema may be torn down mid-run.
 */
final class RestControllerPaginationIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    public function testPaginationWithQueryBuilderUsesDoctrinePaginator(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $qb = $em->createQueryBuilder()
            ->select('c')
            ->from(Content::class, 'c');

        $stack = new RequestStack();
        $stack->push(Request::create('/api/content', 'GET', ['page' => 1, 'limit' => 2]));

        $controller = new class($stack) extends RestController {
            public function __construct(RequestStack $stack)
            {
                parent::__construct($stack, null, null);
            }

            public function publicPagination(mixed $collection): array
            {
                return $this->pagination($collection);
            }
        };

        $result = $controller->publicPagination($qb);

        self::assertIsArray($result['items']);
        self::assertArrayHasKey('paginator', $result);
        self::assertNotNull($result['paginator']);
        self::assertArrayHasKey('total', $result['paginator']);
        self::assertSame(1, $result['paginator']['pages']);
    }
}
