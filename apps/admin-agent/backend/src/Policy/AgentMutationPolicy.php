<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Policy;

use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Explicit agent-only action restrictions layered over the effective admin configuration. */
final class AgentMutationPolicy
{
    /** @var list<string> */
    public const DELETE_ALLOWLIST = ['Category', 'ProductCategory', 'Tag', 'Comment'];

    public static function allows(string $entity, string $action): bool
    {
        return $action !== 'delete' || in_array($entity, self::DELETE_ALLOWLIST, true);
    }

    public static function assertPrepareAllowed(string $entity, string $action): void
    {
        if (!self::allows($entity, $action)) {
            throw new BadRequestHttpException('The assistant cannot delete this entity. Use the standard admin page if your account is allowed.');
        }
    }

    public static function assertExistingProposalAllowed(string $entity, string $action): void
    {
        if (!self::allows($entity, $action)) {
            throw new ProposalConflictException('This deletion is no longer allowed by assistant policy. Use the standard admin page if your account is allowed.');
        }
    }
}
