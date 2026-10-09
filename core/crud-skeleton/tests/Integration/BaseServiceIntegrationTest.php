<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class BaseServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private ContentBaseService $service;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Common\\Entity\\Content c')->execute();

        $this->service = new ContentBaseService(static::getContainer());
    }

    public function testUpdateGetListAndRemoveFlow(): void
    {
        $created = $this->service->update(new Content('first', 'body-a'), ['title' => 'first', 'body' => 'body-a']);
        self::assertInstanceOf(Content::class, $created);
        self::assertNotNull($created->getId());

        $fetchedById = $this->service->get($created->getId());
        self::assertInstanceOf(Content::class, $fetchedById);
        self::assertSame('first', $fetchedById->getTitle());

        $listed = $this->service->list(null, null, true);
        self::assertIsArray($listed);
        self::assertNotEmpty($listed);

        $updated = $this->service->update($fetchedById, ['title' => 'updated-title']);
        self::assertSame('updated-title', $updated->getTitle());

        self::assertTrue($this->service->remove($updated->getId()));
        self::assertNull($this->service->get($updated->getId()));
    }

    public function testUpdatePersistsValues(): void
    {
        $created = $this->service->update(new Content('name-a', 'body-a'), ['title' => 'name-a', 'body' => 'body-a']);
        $updated = $this->service->update($created, ['title' => 'name-b']);

        self::assertInstanceOf(Content::class, $updated);
        self::assertSame('name-b', $updated->getTitle());
    }
}

final class ContentBaseService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Content::class);
    }
}
