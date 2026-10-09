<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionService;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Covers the remaining uncovered line of PromotionService.php:
 *   - line 225 getPriority() fallback for a non-numeric, non-config priority
 *     value (returns 0.0)
 */
#[AllowMockObjectsWithoutExpectations]
final class PromotionServiceCoverageTest extends TestCase
{
    private EntityManagerInterface $em;
    private EntityRepository $rep;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rep = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')->willReturnCallback(function (string $className) {
            if ($className === Promotion::class) {
                return $this->rep;
            }
            return $this->createMock(EntityRepository::class);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($logger, $tokenStorage) {
                return match ($id) {
                    'doctrine.orm.entity_manager' => $this->em,
                    'logger' => $logger,
                    'security.token_storage' => $tokenStorage,
                    default => null,
                };
            });
        $this->container->method('has')->willReturn(true);
    }

    private function createTemplateWithPriority(string $priorityValue): PromotionTemplate
    {
        $template = new PromotionTemplate();
        $template->setName('Priority Template');
        $template->setType(PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setPhase(PromotionTemplate::PHASE_INNER);
        $template->setEnabled(true);
        $template->setAstCache([
            'type' => 'program',
            'data' => ['priority' => ['value' => $priorityValue]],
            'children' => [],
        ]);
        return $template;
    }

    private function createPromotion(string $name, PromotionTemplate $template): Promotion
    {
        $promotion = new Promotion();
        $promotion->setName($name);
        $promotion->setStoreCode('');
        $promotion->setEnabled(true);
        $promotion->setTemplate($template);
        return $promotion;
    }

    public function testNonNumericNonConfigPriorityFallsBackToZero(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;
        $context->totalAmount = 50000;

        $template = $this->createTemplateWithPriority('not-a-number');
        $promotion = $this->createPromotion('Odd Priority', $template);
        // A second promotion forces the usort comparator to invoke getPriority().
        $promotion2 = $this->createPromotion('Another Odd Priority', $this->createTemplateWithPriority('n/a'));

        $this->rep->method('findBy')->willReturn([$promotion, $promotion2]);

        $service = new PromotionService($this->container, []);
        $result = $service->getAvailable($context);

        // The malformed priority values must not crash the sort; both
        // promotions sort with a priority of 0.0 and are returned.
        self::assertCount(2, $result);
        self::assertContains('Odd Priority', [$result[0]->getName(), $result[1]->getName()]);
    }

    #[Group('low-value')]
    public function testNumericStringPrioritySortsStable(): void
    {
        $context = new PriceCalculationContext([]);
        $context->storeCode = null;
        $context->totalAmount = 50000;

        $low = $this->createPromotion('Low', $this->createTemplateWithPriority('10'));
        $high = $this->createPromotion('High', $this->createTemplateWithPriority('999'));

        $this->rep->method('findBy')->willReturn([$low, $high]);

        $service = new PromotionService($this->container, []);
        $result = $service->getAvailable($context);

        self::assertSame('High', $result[0]->getName());
        self::assertSame('Low', $result[1]->getName());
    }
}
