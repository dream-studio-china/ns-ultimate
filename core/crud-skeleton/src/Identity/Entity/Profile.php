<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Identity\Repository\ProfileRepository::class)]
#[ORM\Table(name: 'identity_profile')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_identity_profile_uuid', columns: ['uuid'])]
class Profile
{
    public const LEVEL_BRONZE = 'bronze';
    public const LEVEL_SILVER = 'silver';
    public const LEVEL_GOLD = 'gold';
    public const LEVEL_PLATINUM = 'platinum';
    public const LEVEL_DIAMOND = 'diamond';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'bronze'])]
    private string $level = self::LEVEL_BRONZE;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $avatar = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, string $level = self::LEVEL_BRONZE)
    {
        $this->uuid = UUID::v4();
        $this->user = $user;
        $this->level = $level;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        $name = $this->nickname ?? $this->user?->getUsername() ?? 'N/A';
        return sprintf('%s (%s)', $name, $this->level);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        $this->touch();
        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;
        $this->touch();
        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $this->nickname = $nickname;
        $this->touch();
        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;
        $this->touch();
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        $this->touch();
        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(?\DateTimeImmutable $joinedAt): self
    {
        $this->joinedAt = $joinedAt;
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

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        if ($this->joinedAt === null) {
            $this->joinedAt = new \DateTimeImmutable();
        }
    }
}
