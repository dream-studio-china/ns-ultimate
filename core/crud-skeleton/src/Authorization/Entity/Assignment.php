<?php

declare(strict_types=1);

namespace App\Authorization\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Authorization\Repository\AssignmentRepository::class)]
#[ORM\Table(name: 'authorization_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_authorization_assignment', columns: ['user_uuid', 'role_id', 'scope_type', 'scope_key'])]
#[ORM\Index(columns: ['user_uuid', 'revoked_at'], name: 'idx_authorization_assignment_user_revoked')]
#[ORM\Index(columns: ['scope_type', 'scope_uuid', 'revoked_at'], name: 'idx_authorization_assignment_scope_revoked')]
#[ORM\HasLifecycleCallbacks]
class Assignment
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_STORE = 'store';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 36)]
    private string $userUuid;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', onDelete: 'RESTRICT', nullable: false)]
    private Role $role;

    #[ORM\Column(type: 'string', length: 20)]
    private string $scopeType;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $scopeUuid = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $scopeKey = '';

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $grantedByUuid = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Role $role, string $userUuid, string $scopeType, ?string $scopeUuid = null, ?string $grantedByUuid = null)
    {
        $this->uuid = UUID::v4();
        $this->role = $role;
        $this->userUuid = $userUuid;
        $this->scopeType = $scopeType;
        $this->scopeUuid = $scopeUuid;
        $this->scopeKey = $scopeUuid ?? '';
        $this->grantedByUuid = $grantedByUuid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getUserUuid(): string
    {
        return $this->userUuid;
    }

    public function setUserUuid(string $userUuid): self
    {
        $this->userUuid = $userUuid;

        return $this;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    public function setScopeType(string $scopeType): self
    {
        $this->scopeType = $scopeType;

        return $this;
    }

    public function getScopeUuid(): ?string
    {
        return $this->scopeUuid;
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function setScopeUuid(?string $scopeUuid): self
    {
        $this->scopeUuid = $scopeUuid;
        $this->scopeKey = $scopeUuid ?? '';

        return $this;
    }

    public function getGrantedByUuid(): ?string
    {
        return $this->grantedByUuid;
    }

    public function setGrantedByUuid(?string $grantedByUuid): self
    {
        $this->grantedByUuid = $grantedByUuid;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncScopeKey(): void
    {
        $this->scopeKey = $this->scopeUuid ?? '';
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
        $this->syncScopeKey();
    }

    public function __toString(): string
    {
        return $this->uuid;
    }
}
