<?php

declare(strict_types=1);

namespace App\Tests\Integration\Promotion\Repository;

use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Repository\PromotionTemplateRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PromotionTemplateRepositoryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Promotion\\Entity\\Promotion p')->execute();
        $em->createQuery('DELETE FROM App\\Promotion\\Entity\\PromotionTemplate t')->execute();

        self::ensureKernelShutdown();
    }

    public function testFindByIdReturnsTemplate(): void
    {
        $template = $this->createTemplate('Full Reduction', 'full_reduction');

        $client = static::createClient();
        /** @var PromotionTemplateRepository $repo */
        $repo = $client->getContainer()->get(PromotionTemplateRepository::class);

        $found = $repo->findById($template->getId());
        self::assertNotNull($found);
        self::assertSame('Full Reduction', $found->getName());
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $client = static::createClient();
        /** @var PromotionTemplateRepository $repo */
        $repo = $client->getContainer()->get(PromotionTemplateRepository::class);

        $found = $repo->findById(99999);
        self::assertNull($found);
    }

    private function createTemplate(string $name, string $type): PromotionTemplate
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $t = new PromotionTemplate();
        $t->setName($name);
        $t->setType($type);
        $t->setDsl("type: {$type}\nwhen:\n  cart.subtotal >= 0");

        $em->persist($t);
        $em->flush();

        self::ensureKernelShutdown();
        return $t;
    }
}
