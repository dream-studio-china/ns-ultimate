<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Public;

use App\Common\Entity\Media;
use App\Common\Service\MediaServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/media', name: 'public-media-')]
class MediaController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly MediaServiceInterface $service,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('media')
            ->from(Media::class, 'media')
            ->andWhere('media.user IS NULL');
    }
}
