<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Service\VoucherCommentServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/voucher-comments', name: 'manage-voucher-comments-')]
#[IsGranted('ROLE_ADMIN')]
class VoucherCommentController extends RestController
{
    use ApiView, CreateApiViewMixin, ListApiViewMixin, DetailApiViewMixin;

    /** @var string[] */
    protected array $requiredCreateProperties = ['voucher', 'text'];

    public function __construct(
        protected readonly VoucherCommentServiceInterface $service,
    ) {}

    /**
     * @return array<string, mixed>
     */
    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();

        return ['actor' => $user instanceof User ? $user->getUsername() : 'system'];
    }
}
