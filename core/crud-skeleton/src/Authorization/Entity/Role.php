<?php

declare(strict_types=1);

namespace App\Authorization\Entity;

use App\Core\Utils\UUID;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Authorization\Repository\RoleRepository::class)]
#[ORM\Table(name: 'authorization_role')]
#[ORM\UniqueConstraint(name: 'uniq_authorization_role_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_authorization_role_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Role
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_STORE = 'store';

    public const SCOPES = [self::SCOPE_GLOBAL, self::SCOPE_STORE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $scopeType;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'authorization_role_permission')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'permission_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private Collection $permissions;

    public function __construct(string $code, string $name, string $scopeType)
    {
        $this->uuid = UUID::v4();
        $this->code = $code;
        $this->name = $name;
        $this->scopeType = $scopeType;
        $this->createdAt = new \DateTimeImmutable();
        $this->permissions = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, Permission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(Permission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }

        return $this;
    }

    public function removePermission(Permission $permission): self
    {
        $this->permissions->removeElement($permission);

        return $this;
    }

    public function hasPermission(string $code): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
