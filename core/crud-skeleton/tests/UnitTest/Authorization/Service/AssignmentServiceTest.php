<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Authorization\Service;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Service\AssignmentService;
use App\Core\Utils\UUID;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AssignmentServiceTest extends TestCase
{
    private function createRole(string $code, string $scopeType, int $id): Role
    {
        $role = new Role($code, $code, $scopeType);
        (new \ReflectionProperty(Role::class, 'id'))->setValue($role, $id);

        return $role;
    }

    private function createPermission(string $code): Permission
    {
        [$module, $resource, $action] = array_pad(explode(':', $code, 3), 3, 'default');

        return new Permission($code, $module, $resource, $action, $code);
    }

    private function createContainer(?EntityManagerInterface $em = null, ?AssignmentRepository $repo = null): ContainerInterface
    {
        $em ??= $this->createMock(EntityManagerInterface::class);
        $repo ??= $this->createMock(AssignmentRepository::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class): object => $class === Assignment::class ? $repo : throw new \RuntimeException('unexpected repo'));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'doctrine.orm.entity_manager' => $em,
            'logger' => $this->createMock(LoggerInterface::class),
            'security.token_storage' => $this->createMock(TokenStorageInterface::class),
            'validator' => $this->createMock(ValidatorInterface::class),
            default => null,
        });

        return $container;
    }

    public function testCreateAssignmentHappy(): void
    {
        $userUuid = UUID::v4();
        $role = $this->createRole('store_content_editor', Role::SCOPE_STORE, 1);
        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_STORE, UUID::v4());

        self::assertSame($userUuid, $assignment->getUserUuid());
        self::assertSame(Role::SCOPE_STORE, $assignment->getScopeType());
        self::assertNotNull($assignment->getScopeUuid());
        self::assertTrue($assignment->isActive());
        self::assertFalse($assignment->isRevoked());
        self::assertNotEmpty($assignment->getUuid());
        self::assertSame($role, $assignment->getRole());
        self::assertNull($assignment->getRevokedAt());
    }

    public function testCreateGlobalAssignmentHappy(): void
    {
        $userUuid = UUID::v4();
        $role = $this->createRole('authorization_administrator', Role::SCOPE_GLOBAL, 2);
        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_GLOBAL, null);

        self::assertSame(Assignment::SCOPE_GLOBAL, $assignment->getScopeType());
        self::assertNull($assignment->getScopeUuid());
        self::assertSame('', $assignment->getScopeKey());
        self::assertTrue($assignment->isActive());
    }

    public function testRevokeHappy(): void
    {
        $userUuid = UUID::v4();
        $role = $this->createRole('store_content_editor', Role::SCOPE_STORE, 3);
        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_STORE, UUID::v4());

        self::assertTrue($assignment->isActive());

        $assignment->setRevokedAt(new \DateTimeImmutable());

        self::assertTrue($assignment->isRevoked());
        self::assertFalse($assignment->isActive());
        self::assertNotNull($assignment->getRevokedAt());

        // Reactivate
        $assignment->setRevokedAt(null);
        self::assertTrue($assignment->isActive());
        self::assertFalse($assignment->isRevoked());
    }

    public function testScopeKeySyncOnConstructionAndMutation(): void
    {
        $userUuid = UUID::v4();
        $storeUuid = UUID::v4();
        $role = $this->createRole('store_content_editor', Role::SCOPE_STORE, 4);

        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_STORE, $storeUuid);
        self::assertSame($storeUuid, $assignment->getScopeKey());

        $assignment->setScopeUuid(null);
        self::assertSame('', $assignment->getScopeKey());

        $newStore = UUID::v4();
        $assignment->setScopeUuid($newStore);
        self::assertSame($newStore, $assignment->getScopeKey());
        self::assertSame($newStore, $assignment->getScopeUuid());
    }

    public function testDuplicateGlobalAssignmentViolatesUniqueConstraint(): void
    {
        // Simulate BaseService::update() wrapping UniqueConstraintViolationException into ValidatorException
        $userUuid = UUID::v4();
        $role = $this->createRole('authorization_administrator', Role::SCOPE_GLOBAL, 10);
        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        // persist succeeds, flush throws unique violation
        $em->expects(self::once())->method('persist')->with($assignment);
        $em->expects(self::once())->method('flush')->willThrowException(
            $this->createMock(UniqueConstraintViolationException::class)
        );
        // mock validator returns no violations so we reach flush
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === 'validator' || $id === 'doctrine.orm.entity_manager' || $id === 'logger' || $id === 'security.token_storage');
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'doctrine.orm.entity_manager' => $em,
            'validator' => $validator,
            'logger' => $this->createMock(LoggerInterface::class),
            'security.token_storage' => $this->createMock(TokenStorageInterface::class),
            default => null,
        });

        $service = new AssignmentService($container);

        // BaseService::update should translate UniqueConstraintViolationException to ValidatorException with 'Duplication entries'
        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $this->expectExceptionMessage('Duplication entries');

        $service->update($assignment, []);
    }

    public function testAssignmentServiceNewCreatesInstanceWithoutConstructor(): void
    {
        // AssignmentService::new() uses newInstanceWithoutConstructor when ctor requires args
        $container = $this->createContainer();
        $service = new AssignmentService($container);
        $instance = $service->new();

        self::assertInstanceOf(Assignment::class, $instance);
        // newInstanceWithoutConstructor leaves properties uninitialized; uuid not set via ctor
        // we just assert it's an Assignment instance
    }

    public function testAssignmentServiceRemoveDelegatesToEntityManager(): void
    {
        $userUuid = UUID::v4();
        $role = $this->createRole('store_content_editor', Role::SCOPE_STORE, 5);
        $assignment = new Assignment($role, $userUuid, Assignment::SCOPE_STORE, UUID::v4());
        (new \ReflectionProperty(Assignment::class, 'id'))->setValue($assignment, 99);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('find')->with(99)->willReturn($assignment);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Assignment::class)->willReturn($repo);
        $em->expects(self::once())->method('remove')->with($assignment);
        $em->expects(self::once())->method('flush');

        $container = $this->createContainer($em, $repo);
        $service = new AssignmentService($container);

        self::assertTrue($service->remove($assignment));
    }
}
