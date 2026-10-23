<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Controller;

use NsUltimate\Apps\AdminAgent\Backend\Capability\CapabilityCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fixed read-only capability metadata. It never accepts an entity, field or endpoint parameter and
 * never performs target lookup or domain writes.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[IsGranted('ROLE_ADMIN')]
#[Route('/agent/capabilities', name: 'admin_agent_capabilities_')]
final class CapabilityController extends AbstractController
{
    public function __construct(private readonly CapabilityCatalog $catalog) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $request->query->all();
        $locale = $query['_locale'] ?? null;
        // The authenticated admin client adds _locale to every request; no filter or entity
        // selector is accepted because the catalog is fixed and account-filtered server-side.
        if (array_diff(array_keys($query), ['_locale']) !== []
            || ($locale !== null && (!is_string($locale) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,34}$/D', $locale)))) {
            throw new BadRequestHttpException('Only an optional locale is accepted.');
        }

        return new JsonResponse(
            $this->catalog->encode($this->catalog->build()),
            200,
            ['Cache-Control' => 'no-store'],
            true,
        );
    }
}
