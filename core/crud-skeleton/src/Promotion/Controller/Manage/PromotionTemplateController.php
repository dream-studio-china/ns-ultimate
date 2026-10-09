<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Promotion\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\Dsl\DslSyntaxException;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/promotion-templates', name: 'manage-promotion-templates-')]
#[IsGranted('ROLE_ADMIN')]
class PromotionTemplateController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'type', 'dsl'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = [
        'name', 'description', 'type', 'phase', 'enabled', 'dsl', 'fields',
    ];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = [
        'name', 'description', 'type', 'phase', 'enabled', 'dsl', 'fields',
    ];

    public function __construct(
        protected readonly PromotionTemplateServiceInterface $service,
    ) {}

    #[Route('/{id}/validate', name: 'validate', methods: ['POST'])]
    public function validateAction(int $id): Response
    {
        $template = $this->service->get($id);
        if (!$template instanceof PromotionTemplate) {
            return $this->warning('Template not found.', 404, '', 404);
        }

        $result = $this->service->parseDsl($template->getDsl());
        if (!empty($result['errors'])) {
            return $this->warning($result['errors'][0]['message'], 422, '', 422);
        }

        return $this->success($result['ast'], 'DSL is valid');
    }

    #[Route('/{id}/dry-run', name: 'dry-run', methods: ['POST'])]
    public function dryRunAction(int $id): Response
    {
        $template = $this->service->get($id);
        if (!$template instanceof PromotionTemplate) {
            return $this->warning('Template not found.', 404, '', 404);
        }

        $result = $this->service->simulate($template, []);
        return $this->success($result);
    }
}
