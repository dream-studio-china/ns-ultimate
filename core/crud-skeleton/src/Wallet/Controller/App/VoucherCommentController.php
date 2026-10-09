<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Repository\VoucherCommentRepository;
use App\Wallet\Service\VoucherCommentServiceInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/voucher-comments', name: 'app-voucher-comments-')]
#[IsGranted('ROLE_USER')]
class VoucherCommentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly VoucherCommentServiceInterface $service,
        private readonly VoucherCommentRepository $commentRepository,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        $user = $this->getUser();

        return $this->commentRepository->createQueryBuilder('entity')
            ->join('entity.voucher', 'v')
            ->join('v.wallet', 'w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('entity.createdAt', 'DESC');
    }
}
