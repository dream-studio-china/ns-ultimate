<?php

declare(strict_types=1);

namespace App\Tests\Integration\Promotion\Repository;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Repository\PromotionRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PromotionRepositoryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Promotion\\Entity\\Promotion p')->execute();
        $em->createQuery('DELETE FROM App\\Promotion\\Entity\\PromotionTemplate pt')->execute();

        self::ensureKernelShutdown();
    }

    public function testFindByIdReturnsPromotion(): void
    {
        $promotion = $this->createPromotion('Test Promo', 'store-1');

        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        $found = $repo->findById($promotion->getId());

        self::assertNotNull($found);
        self::assertSame($promotion->getId(), $found->getId());
        self::assertSame('Test Promo', $found->getName());
        self::assertSame('store-1', $found->getStoreCode());
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        $found = $repo->findById(99999);

        self::assertNull($found);
    }

    public function testRepositoryResolvesCorrectly(): void
    {
        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        self::assertInstanceOf(PromotionRepository::class, $repo);
    }

    public function testFindByIdReturnsPromotionWithTemplate(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $template = new PromotionTemplate();
        $template->setName('Custom Template');
        $template->setType(PromotionTemplate::TYPE_DISCOUNT);

        $promotion = new Promotion();
        $promotion->setName('Templated Promo');
        $promotion->setStoreCode('store-2');
        $promotion->setTemplate($template);

        $em->persist($template);
        $em->persist($promotion);
        $em->flush();

        self::ensureKernelShutdown();

        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        $found = $repo->findById($promotion->getId());

        self::assertNotNull($found);
        self::assertNotNull($found->getTemplate());
        self::assertSame($template->getId(), $found->getTemplate()->getId());
        self::assertSame('Custom Template', $found->getTemplate()->getName());
    }

    public function testFindReturnsPromotionWithConfig(): void
    {
        $promotion = $this->createPromotion('Config Promo', 'store-3', ['threshold' => 100]);

        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        $found = $repo->findById($promotion->getId());

        self::assertNotNull($found);
        self::assertSame(['threshold' => 100], $found->getConfig());
    }

    public function testFindByIdReturnsPromotionWithTimeWindow(): void
    {
        $start = new \DateTimeImmutable('2025-01-01');
        $end = new \DateTimeImmutable('2025-12-31');
        $promotion = $this->createPromotion('Timed Promo', 'store-4', null, false, $start, $end);

        $client = static::createClient();
        /** @var PromotionRepository $repo */
        $repo = $client->getContainer()->get(PromotionRepository::class);

        $found = $repo->findById($promotion->getId());

        self::assertNotNull($found);
        self::assertNotNull($found->getStartTime());
        self::assertNotNull($found->getEndTime());
        self::assertEquals($start->format('Y-m-d'), $found->getStartTime()->format('Y-m-d'));
        self::assertEquals($end->format('Y-m-d'), $found->getEndTime()->format('Y-m-d'));
    }

    // ───────────────────── helpers ─────────────────────

    private function createPromotion(
        string $name,
        string $storeCode,
        ?array $config = null,
        bool $enabled = true,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null,
    ): Promotion {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $template = new PromotionTemplate();
        $template->setName($name . ' Template');
        $template->setType(PromotionTemplate::TYPE_FULL_REDUCTION);

        $promotion = new Promotion();
        $promotion->setName($name);
        $promotion->setStoreCode($storeCode);
        $promotion->setTemplate($template);
        $promotion->setEnabled($enabled);

        if ($startTime !== null) {
            $promotion->setStartTime($startTime);
        }
        if ($endTime !== null) {
            $promotion->setEndTime($endTime);
        }
        if ($config !== null) {
            $promotion->setConfig($config);
        }

        $em->persist($template);
        $em->persist($promotion);
        $em->flush();

        self::ensureKernelShutdown();

        return $promotion;
    }
}
