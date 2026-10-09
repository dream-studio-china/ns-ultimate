<?php

declare(strict_types=1);

namespace App\Store\Security;

use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Store> */
final class StoreAuthorizationVoter extends Voter
{
    public function __construct(
        private readonly MembershipServiceInterface $membershipService,
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Store && str_starts_with($attribute, 'store:');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Store) {
            return false;
        }

        return $this->membershipService->isAuthorized($subject, $user->getUuid())
            && $this->authorizationService->can($user, $attribute, AuthorizationScope::store($subject->getUuid()));
    }
}
