<?php

declare(strict_types=1);

namespace App\Authorization\Security;

use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Authorization\Service\ScopedResourceInterface;
use App\Identity\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Authorization permissions.
 *
 * Supports attributes like "common:content:update" or "authorization:role:manage".
 * Subject may be:
 *  - null (global decision)
 *  - AuthorizationScope instance
 *  - ScopedResourceInterface instance
 *  - array with 'scope' => AuthorizationScope
 *
 * @extends Voter<string, mixed>
 */
class AuthorizationVoter extends Voter
{
    public const PREFIX = '';

    public function __construct(
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // We vote on any permission-like attribute containing colon, or any attribute if subject is AuthorizationScope related
        if ($subject instanceof AuthorizationScope || $subject instanceof ScopedResourceInterface) {
            return true;
        }
        if (\is_array($subject) && isset($subject['scope']) && $subject['scope'] instanceof AuthorizationScope) {
            return true;
        }
        // A global permission may omit its subject. Scoped permissions must
        // provide a scope-bearing subject so assignments cannot bleed scopes.
        return $subject === null && str_contains($attribute, ':');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $scope = $this->resolveScope($subject);

        return $this->authorizationService->can($user, $attribute, $scope);
    }

    private function resolveScope(mixed $subject): ?AuthorizationScope
    {
        if ($subject instanceof AuthorizationScope) {
            return $subject;
        }
        if ($subject instanceof ScopedResourceInterface) {
            return $subject->getAuthorizationScope();
        }
        if (\is_array($subject) && isset($subject['scope']) && $subject['scope'] instanceof AuthorizationScope) {
            return $subject['scope'];
        }
        if (\is_object($subject) && method_exists($subject, 'getStoreUuid')) {
            $uuid = $subject->getStoreUuid();
            if (\is_string($uuid) && $uuid !== '') {
                try {
                    return AuthorizationScope::store($uuid);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }
}
