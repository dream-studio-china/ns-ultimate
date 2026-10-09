<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use App\Common\Repository\ContentRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ContentRepositoryIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private ContentRepository $repository;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Common\\Entity\\Content c')->execute();

        $repository = $this->em->getRepository(Content::class);
        self::assertInstanceOf(ContentRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testFindLatestReturnsNewestFirstAndRespectsLimit(): void
    {
        $old = $this->createContentWithCreatedAt('old', new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $middle = $this->createContentWithCreatedAt('middle', new \DateTimeImmutable('2024-01-02T00:00:00+00:00'));
        $new = $this->createContentWithCreatedAt('new', new \DateTimeImmutable('2024-01-03T00:00:00+00:00'));
        $this->em->flush();

        $latestTwo = $this->repository->findLatest(2);

        self::assertCount(2, $latestTwo);
        self::assertSame($new->getId(), $latestTwo[0]->getId());
        self::assertSame($middle->getId(), $latestTwo[1]->getId());
        self::assertNotSame($old->getId(), $latestTwo[0]->getId());
    }

    public function testFindByIdReturnsEntityOrNull(): void
    {
        $content = $this->createContentWithCreatedAt('needle', new \DateTimeImmutable('2024-02-01T00:00:00+00:00'));
        $this->em->flush();

        $found = $this->repository->findById((int) $content->getId());
        $missing = $this->repository->findById(99999999);

        self::assertInstanceOf(Content::class, $found);
        self::assertSame('needle', $found->getTitle());
        self::assertNull($missing);
    }

    private function createContentWithCreatedAt(string $title, \DateTimeImmutable $createdAt): Content
    {
        $content = new Content($title, $title . '-body');
        $property = new \ReflectionProperty(Content::class, 'createdAt');
        $property->setValue($content, $createdAt);

        $this->em->persist($content);

        return $content;
    }
}
