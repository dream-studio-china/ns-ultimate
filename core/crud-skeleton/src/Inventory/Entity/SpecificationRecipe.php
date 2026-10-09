<?php

declare(strict_types=1);

namespace App\Inventory\Entity;

use App\Core\Utils\UUID;
use App\Inventory\Repository\SpecificationRecipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpecificationRecipeRepository::class)]
#[ORM\Table(name: 'inventory_specification_recipe')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_recipe_specification', columns: ['specification_uuid'])]
class SpecificationRecipe
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'specification_uuid', type: 'string', length: 36, unique: true)]
    private string $specificationUuid;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /**
     * @var Collection<int, RecipeLine>
     */
    #[ORM\OneToMany(targetEntity: RecipeLine::class, mappedBy: 'recipe', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sort' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $specificationUuid)
    {
        $this->uuid = UUID::v4();
        $this->specificationUuid = $specificationUuid;
        $this->lines = new ArrayCollection();
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

    public function getSpecificationUuid(): string
    {
        return $this->specificationUuid;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return list<RecipeLine>
     */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            throw new \InvalidArgumentException('Invalid recipe status.');
        }

        $this->status = $status;

        return $this->touch();
    }

    public function addLine(RecipeLine $line): self
    {
        foreach ($this->lines as $existing) {
            if ($existing->getMaterial()->getUuid() === $line->getMaterial()->getUuid()) {
                throw new \LogicException('A recipe cannot contain a material more than once.');
            }
        }

        $line->setRecipe($this);
        $this->lines->add($line);

        return $this->touch();
    }

    public function removeLine(RecipeLine $line): self
    {
        $this->lines->removeElement($line);

        return $this->touch();
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
