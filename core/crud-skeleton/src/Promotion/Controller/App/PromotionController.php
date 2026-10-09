<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Promotion\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Promotion\Entity\Promotion;
use App\Promotion\Service\PromotionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/promotions', name: 'app-promotions-')]
class PromotionController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly PromotionServiceInterface $service,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {}

    /** @return array<string, mixed>|QueryBuilder */
    protected function commonFilter(): array|QueryBuilder
    {
        if ($this->entityManager === null) {
            return ['enabled' => true];
        }

        $now = new \DateTimeImmutable();
        $qb = $this->entityManager->createQueryBuilder()
            ->select('promotion')
            ->from(Promotion::class, 'promotion')
            ->innerJoin('promotion.template', 'template')
            ->andWhere('promotion.enabled = :enabled')
            ->andWhere('template.enabled = :templateEnabled')
            ->andWhere('(promotion.startTime IS NULL OR promotion.startTime <= :now)')
            ->andWhere('(promotion.endTime IS NULL OR promotion.endTime >= :now)')
            ->setParameter('enabled', true)
            ->setParameter('templateEnabled', true)
            ->setParameter('now', $now);

        $storeCode = $this->getRequestStack()->getCurrentRequest()?->query->get('storeCode');
        if (is_string($storeCode) && $storeCode !== '') {
            $qb->andWhere('promotion.storeCode = :storeCode')->setParameter('storeCode', $storeCode);
        }

        return $qb;
    }
}
