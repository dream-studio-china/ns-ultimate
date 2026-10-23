<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Unit;

use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Policy\AgentMutationPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AgentMutationPolicyTest extends TestCase
{
    public function testDeleteAllowlistIsExplicitAndDoesNotRestrictOtherActions(): void
    {
        foreach (['Category', 'ProductCategory', 'Tag', 'Comment'] as $entity) {
            self::assertTrue(AgentMutationPolicy::allows($entity, 'delete'));
        }
        foreach (['Content', 'Counter', 'Product', 'User', 'Wallet', 'Store'] as $entity) {
            self::assertFalse(AgentMutationPolicy::allows($entity, 'delete'));
        }
        self::assertTrue(AgentMutationPolicy::allows('Content', 'update'));
    }

    public function testDeniedDeleteFailsPrepareAndRevokesPendingProposal(): void
    {
        try {
            AgentMutationPolicy::assertPrepareAllowed('Content', 'delete');
            self::fail('A denied delete must fail before it can be prepared.');
        } catch (BadRequestHttpException) {
            self::assertTrue(true);
        }

        $this->expectException(ProposalConflictException::class);
        AgentMutationPolicy::assertExistingProposalAllowed('Content', 'delete');
    }
}
