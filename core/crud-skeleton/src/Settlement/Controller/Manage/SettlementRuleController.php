<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Settlement\Entity\SettlementRule;
use App\Settlement\Service\SettlementRuleConfiguration;
use App\Settlement\Service\SettlementRuleServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-rules', name: 'manage-settlement-rules-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementRuleController extends RestController
{
    use ApiView;
    use CreateApiViewMixin;
    use DetailApiViewMixin;
    use ListApiViewMixin;

    public function __construct(
        protected readonly SettlementRuleServiceInterface $service,
        private readonly SettlementRuleConfiguration $configuration,
    ) {
    }

    /** @var list<string> */
    protected array $requiredCreateProperties = ['code', 'name'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['code', 'name'];

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof SettlementRule
            || !is_string($content['code'] ?? null)
            || !is_string($content['name'] ?? null)
            || trim($content['code']) === ''
            || trim($content['name']) === '') {
            throw new \InvalidArgumentException('Rule code and name are required.');
        }

        return $content;
    }

    #[Route('/configuration', name: 'configuration', methods: ['GET'])]
    public function configurationAction(): Response
    {
        return $this->success($this->configuration->schema());
    }

}
