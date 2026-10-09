<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Authorization\Security;

use App\Authorization\Security\AuthorizationVoter;
use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Authorization\Service\ScopedResourceInterface;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuthorizationVoterTest extends TestCase
{
    /** @var AuthorizationServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private AuthorizationServiceInterface $auth;
    private AuthorizationVoter $voter;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(AuthorizationServiceInterface::class);
        $this->voter = new AuthorizationVoter($this->auth);
    }

    // ---------- helpers ----------

    private function token(mixed $user): TokenInterface
    {
        $t = $this->createMock(TokenInterface::class);
        $t->method('getUser')->willReturn($user);

        return $t;
    }

    private function invokeSupports(string $attribute, mixed $subject): bool
    {
        $m = new \ReflectionMethod(AuthorizationVoter::class, 'supports');

        return $m->invoke($this->voter, $attribute, $subject);
    }

    private function invokeVoteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $m = new \ReflectionMethod(AuthorizationVoter::class, 'voteOnAttribute');

        return $m->invoke($this->voter, $attribute, $subject, $token, null);
    }

    private function nonUser(): UserInterface
    {
        return $this->createMock(UserInterface::class);
    }

    // ---------- supports() ----------

    public function testSupportsReturnsTrueForPermissionAttributeWithColonEvenWhenSubjectNull(): void
    {
        self::assertTrue($this->invokeSupports('common:content:update', null));
        self::assertTrue($this->invokeSupports('authorization:role:manage', null));
        self::assertTrue($this->invokeSupports('a:b', null));
    }

    public function testSupportsReturnsFalseForAttributeWithoutColonAndNoScopeSubject(): void
    {
        self::assertFalse($this->invokeSupports('noccolon', null));
        self::assertFalse($this->invokeSupports('permission', null));
        self::assertFalse($this->invokeSupports('', null));
    }

    public function testSupportsTrueForAuthorizationScopeSubject(): void
    {
        $scope = AuthorizationScope::global();
        self::assertTrue($this->invokeSupports('any', $scope));
        self::assertTrue($this->invokeSupports('any:attribute', $scope));
        self::assertTrue($this->invokeSupports('noccolon', $scope));

        $storeScope = AuthorizationScope::store(UUID::v4());
        self::assertTrue($this->invokeSupports('any', $storeScope));
    }

    public function testSupportsTrueForScopedResourceInterfaceSubject(): void
    {
        $resource = $this->createMock(ScopedResourceInterface::class);
        $resource->method('getAuthorizationScope')->willReturn(AuthorizationScope::global());

        self::assertTrue($this->invokeSupports('any', $resource));
        self::assertTrue($this->invokeSupports('store:order:read', $resource));
        self::assertTrue($this->invokeSupports('noccolon', $resource));
    }

    public function testSupportsTrueForArrayWithScopeKey(): void
    {
        $scope = AuthorizationScope::global();
        self::assertTrue($this->invokeSupports('any', ['scope' => $scope]));
        self::assertTrue($this->invokeSupports('noccolon', ['scope' => $scope]));
        self::assertTrue($this->invokeSupports('a:b', ['scope' => $scope]));
    }

    public function testSupportsFalseForArrayWithoutValidScope(): void
    {
        self::assertFalse($this->invokeSupports('a:b', []));
        self::assertFalse($this->invokeSupports('a:b', ['scope' => null]));
        self::assertFalse($this->invokeSupports('a:b', ['scope' => 'not-a-scope']));
        self::assertFalse($this->invokeSupports('noccolon', ['scope' => 'string']));
        // missing 'scope' key even with colon and null? subject is array not null so false unless valid scope
        self::assertFalse($this->invokeSupports('a:b', ['other' => AuthorizationScope::global()]));
    }

    public function testSupportsFalseForArbitraryObject(): void
    {
        self::assertFalse($this->invokeSupports('a:b', new \stdClass()));
        self::assertFalse($this->invokeSupports('noccolon', new \stdClass()));
        self::assertFalse($this->invokeSupports('a:b', 'string-subject'));
        self::assertFalse($this->invokeSupports('a:b', 123));
    }

    // ---------- vote() abstain / grant / deny ----------

    public function testVoteAbstainsWhenSupportsFalse(): void
    {
        $user = new User();
        $this->auth->expects(self::never())->method('can');

        $result = $this->voter->vote($this->token($user), new \stdClass(), ['store:order:read']);
        // subject is stdClass, attribute has colon but subject not null nor scope -> supports false -> abstain
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteAbstainsForPermissionWithoutColonAndNullSubjectSadPath(): void
    {
        $user = new User();
        $this->auth->expects(self::never())->method('can');

        $result = $this->voter->vote($this->token($user), null, ['nocolon']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);

        // also verify supports directly
        self::assertFalse($this->invokeSupports('nocolon', null));
    }

    public function testVoteDeniesWhenTokenUserIsNotUserInstance(): void
    {
        // use a supports-true case: attribute with colon and null subject
        $this->auth->expects(self::never())->method('can');

        $result = $this->voter->vote($this->token($this->nonUser()), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);

        $result2 = $this->voter->vote($this->token(null), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result2);

        $result3 = $this->voter->vote($this->token($this->nonUser()), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result3);
    }

    public function testVoteOnAttributeDeniesWhenUserNotInstanceEvenWithScopeSubject(): void
    {
        $scope = AuthorizationScope::global();
        $this->auth->expects(self::never())->method('can');

        $denied = $this->invokeVoteOnAttribute('any', $scope, $this->token($this->nonUser()));
        self::assertFalse($denied);
    }

    public function testHappyPathReturnsGrantedWhenCanTrue(): void
    {
        $user = new User();
        $this->auth->expects(self::once())->method('can')
            ->with($user, 'common:content:update', null)
            ->willReturn(true);

        $result = $this->voter->vote($this->token($user), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testReturnsDeniedWhenCanFalse(): void
    {
        $user = new User();
        $this->auth->expects(self::once())->method('can')
            ->with($user, 'common:content:update', null)
            ->willReturn(false);

        $result = $this->voter->vote($this->token($user), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---------- delegation with resolved scope ----------

    public function testVoteDelegatesWithNullScope(): void
    {
        $user = new User();
        $this->auth->expects(self::once())->method('can')
            ->with($user, 'common:content:update', self::equalTo(null))
            ->willReturn(true);

        $result = $this->voter->vote($this->token($user), null, ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDelegatesWithAuthorizationScope(): void
    {
        $user = new User();
        $scope = AuthorizationScope::store(UUID::v4());

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', self::equalTo($scope))
            ->willReturn(true);

        $result = $this->voter->vote($this->token($user), $scope, ['store:order:read']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDelegatesWithAuthorizationScopeDenied(): void
    {
        $user = new User();
        $scope = AuthorizationScope::global();

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', self::equalTo($scope))
            ->willReturn(false);

        $result = $this->voter->vote($this->token($user), $scope, ['store:order:read']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteDelegatesWithScopedResourceInterface(): void
    {
        $user = new User();
        $scope = AuthorizationScope::store(UUID::v4());
        $resource = $this->createMock(ScopedResourceInterface::class);
        $resource->method('getAuthorizationScope')->willReturn($scope);

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', self::equalTo($scope))
            ->willReturn(true);

        $result = $this->voter->vote($this->token($user), $resource, ['store:order:read']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDelegatesWithScopedResourceInterfaceReturningNull(): void
    {
        $user = new User();
        $resource = $this->createMock(ScopedResourceInterface::class);
        $resource->method('getAuthorizationScope')->willReturn(null);

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'any:perm', self::equalTo(null))
            ->willReturn(false);

        $result = $this->voter->vote($this->token($user), $resource, ['any:perm']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteDelegatesWithArrayScope(): void
    {
        $user = new User();
        $scope = AuthorizationScope::global();

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'common:content:update', self::equalTo($scope))
            ->willReturn(true);

        $result = $this->voter->vote($this->token($user), ['scope' => $scope], ['common:content:update']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDelegatesWithObjectHavingGetStoreUuidHappyPath(): void
    {
        $user = new User();
        $uuid = UUID::v4();
        $subject = new class($uuid) {
            public function __construct(private string $uuid) {}
            public function getStoreUuid(): string { return $this->uuid; }
        };

        // invoke voteOnAttribute directly because supports() is false for this subject (by design)
        // to verify resolveScope happy path delegates to store scope
        $expectedScope = AuthorizationScope::store($uuid);

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', self::callback(function (?AuthorizationScope $s) use ($expectedScope): bool {
                return $s instanceof AuthorizationScope && $s->type === $expectedScope->type && $s->uuid === $expectedScope->uuid;
            }))
            ->willReturn(true);

        $granted = $this->invokeVoteOnAttribute('store:order:read', $subject, $this->token($user));
        self::assertTrue($granted);
    }

    public function testVoteDelegatesWithObjectHavingGetStoreUuidInvalidUuidFallback(): void
    {
        $user = new User();
        $subject = new class {
            public function getStoreUuid(): string { return 'invalid-uuid'; }
        };

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', null)
            ->willReturn(false);

        $denied = $this->invokeVoteOnAttribute('store:order:read', $subject, $this->token($user));
        self::assertFalse($denied);
    }

    public function testVoteResolvesScopeEmptyStringUuidFallback(): void
    {
        $user = new User();
        $subject = new class {
            public function getStoreUuid(): string { return ''; }
        };

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', null)
            ->willReturn(false);

        $denied = $this->invokeVoteOnAttribute('store:order:read', $subject, $this->token($user));
        self::assertFalse($denied);
    }

    public function testVoteResolvesScopeNonStringUuidFallback(): void
    {
        $user = new User();
        $subject = new class {
            public function getStoreUuid(): mixed { return 12345; }
        };

        $this->auth->expects(self::once())->method('can')
            ->with($user, 'store:order:read', null)
            ->willReturn(true);

        $granted = $this->invokeVoteOnAttribute('store:order:read', $subject, $this->token($user));
        self::assertTrue($granted);
    }

    public function testVoteWithGetStoreUuidObjectViaVoteAbstainsButVoteOnAttributeStillResolves(): void
    {
        // Demonstrate that vote() abstains for getStoreUuid object because supports() is false,
        // which is current implementation behavior.
        $user = new User();
        $uuid = UUID::v4();
        $subject = new class($uuid) {
            public function __construct(private string $uuid) {}
            public function getStoreUuid(): string { return $this->uuid; }
        };

        $this->auth->expects(self::never())->method('can');

        $result = $this->voter->vote($this->token($user), $subject, ['store:order:read']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testSupportsStillTrueForPermissionWithColonEvenWhenSubjectNullAndCanGranted(): void
    {
        $user = new User();
        $this->auth->expects(self::once())->method('can')->willReturn(true);

        self::assertTrue($this->invokeSupports('x:y', null));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->token($user), null, ['x:y']));
    }
}
