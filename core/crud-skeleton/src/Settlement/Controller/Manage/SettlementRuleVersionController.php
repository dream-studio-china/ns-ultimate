<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Settlement\Service\SettlementRuleVersionServiceInterface;
use App\Settlement\Entity\SettlementRuleVersion;
use App\Settlement\Repository\SettlementRuleRepository;
use App\Settlement\Service\SettlementRuleServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-rule-versions', name: 'manage-settlement-rule-versions-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementRuleVersionController extends RestController
{
    use ApiView;
    use CreateApiViewMixin;
    use DetailApiViewMixin;
    use ListApiViewMixin;
    use UpdateApiViewMixin;

    public function __construct(
        protected readonly SettlementRuleVersionServiceInterface $service,
        private readonly SettlementRuleServiceInterface $ruleService,
        private readonly SettlementRuleRepository $ruleRepository,
    ) {
    }

    /** @var list<string> */
    protected array $requiredCreateProperties = ['ruleUuid', 'definition', 'priority', 'effectiveFrom'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['ruleUuid', 'definition', 'priority', 'effectiveFrom', 'effectiveTo'];

    /** @var list<string> */
    protected array $requiredUpdateProperties = ['definition', 'priority', 'effectiveFrom'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['definition', 'priority', 'effectiveFrom', 'effectiveTo'];

    /** @return array<string, mixed> */
    protected function defaultCreateValues(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof SettlementRuleVersion
            || !is_string($content['ruleUuid'] ?? null)
            || !is_array($content['definition'] ?? null)
            || !is_int($content['priority'] ?? null)
            || !is_string($content['effectiveFrom'] ?? null)) {
            throw new \InvalidArgumentException('ruleUuid, definition, integer priority, and effectiveFrom are required.');
        }
        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        return $entity;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof SettlementRuleVersion) {
            throw new \InvalidArgumentException('Settlement rule version not found.');
        }

        return $content;
    }

    #[Route('/{uuid}/publish', name: 'publish', methods: ['POST'])]
    public function publishAction(string $uuid): Response
    {
        $version = $this->version($uuid);
        if ($version === null) {
            return $this->warning('Settlement rule version not found.', 404, '', 404);
        }
        $rule = $this->ruleRepository->findByUuid($version->getRuleUuid());
        if ($rule === null) {
            return $this->warning('Settlement rule not found.', 404, '', 404);
        }

        try {
            $actor = $this->getUser()?->getUserIdentifier() ?? 'system';
            return $this->success($this->ruleService->publishVersion($rule, $version, $actor), 'Settlement rule version published');
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    private function version(string $uuid): ?SettlementRuleVersion
    {
        $version = $this->service->get(['uuid' => $uuid], false);
        return $version instanceof SettlementRuleVersion ? $version : null;
    }
}
