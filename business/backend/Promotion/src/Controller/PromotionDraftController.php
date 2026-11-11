<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Controller;

use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Entity\Promotion;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Promotion\Service\PromotionDraftWorkbench;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PromotionDraftController extends AbstractController
{
    #[Route('/api/v1/manage/promotion-drafts/check', name: 'business-promotion-draft-check', methods: ['POST'])]
    public function check(Request $request, PromotionDraftWorkbench $workbench, EntityManagerInterface $em): JsonResponse
    {
        if (strlen($request->getContent()) > 64000) return $this->json(['error' => 'Draft request exceeds 64000 bytes.'], 413);
        $input = $request->toArray();
        if (array_diff(array_keys($input), ['template', 'templateId', 'campaignId', 'config', 'cases']) !== [] || count(array_intersect(array_keys($input), ['template', 'templateId', 'campaignId'])) !== 1) return $this->json(['message' => 'Provide exactly one of template, templateId or campaignId.'], 400);
        $dependency = null;
        $resolved = null;
        if (array_key_exists('campaignId', $input)) {
            if (!is_int($input['campaignId']) || $input['campaignId'] < 1 || count($input) !== 1) return $this->json(['message' => 'Campaign inspection accepts only a positive campaignId.'], 400);
            $campaign = $em->find(Promotion::class, $input['campaignId']);
            if (!$campaign instanceof Promotion || !$campaign->getTemplate()) return $this->json(['message' => 'Campaign or template not found.'], 404);
            $input['templateId'] = $campaign->getTemplate()->getId();
            $input['config'] = $campaign->getConfig() ?? [];
            $resolved = ['campaignId' => $input['campaignId'], 'templateId' => $input['templateId'], 'config' => $input['config']];
        }
        if (array_key_exists('templateId', $input)) {
            if (!is_int($input['templateId']) || $input['templateId'] < 1) return $this->json(['error' => 'templateId must be a positive integer.'], 400);
            $template = $em->find(PromotionTemplate::class, $input['templateId']);
            if (!$template instanceof PromotionTemplate) return $this->json(['error' => 'Template not found.'], 404);
            $input['template'] = ['type' => $template->getType(), 'phase' => $template->getPhase(), 'dsl' => $template->getDsl(), 'fields' => $template->getFields()];
            $dependency = hash('sha256', (string) json_encode([$input['template'], $template->isEnabled()]));
        }
        // No persist, flush, order creation, external calls or live quote pipeline.
        $result = $workbench->inspect($input) + ['templateRevision' => $dependency];
        if ($resolved !== null && $result['valid']) $result['resolved'] = $resolved;
        if (isset($result['parameterSchema']) && $result['parameterSchema']['properties'] === []) $result['parameterSchema']['properties'] = new \stdClass();
        return $this->json(['code' => 0, 'data' => $result, 'message' => 'Draft checked; nothing saved.']);
    }
}
