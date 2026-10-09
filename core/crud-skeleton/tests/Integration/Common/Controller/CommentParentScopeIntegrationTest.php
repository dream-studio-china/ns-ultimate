<?php

declare(strict_types=1);

namespace App\Tests\Integration\Common\Controller;

use App\Common\Entity\Comment;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents Bug 2: the App CommentController accepts an arbitrary parent comment id
 * without validating that the parent belongs to the same entityType/entityId scope.
 */
final class CommentParentScopeIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM ' . Comment::class)->execute();
    }

    public function testReplyParentMustBelongToSameEntityScope(): void
    {
        // SKIPPED — documents Bug 2: the App create endpoint accepts a `parent` comment
        // that belongs to a DIFFERENT entityType/entityId, creating orphaned cross-entity
        // threads. A correct implementation should reject it with 400.
        $this->markTestSkipped(
            'App comment create accepts cross-entity parent ids (src/Common/Controller/App/CommentController.php:22). '
            . 'See docs/issues/coverage-2026-08-09/common-controllers-entity.md Bug 2.'
        );

        $parent = new Comment('Parent on Page', 'Page', 1);
        $this->em->persist($parent);
        $this->em->flush();

        // Reply targets Content/99 but its parent belongs to Page/1.
        $this->client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Cross-entity reply',
            'entityType' => 'Content',
            'entityId' => 99,
            'parent' => $parent->getId(),
        ], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }
}
