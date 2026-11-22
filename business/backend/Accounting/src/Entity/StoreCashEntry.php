<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ns_store_cash_entry')]
#[ORM\Index(name: 'idx_store_cash_month', columns: ['store_uuid', 'entry_date', 'uuid'])]
class StoreCashEntry
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $uuid;
    #[ORM\Column(length: 36)]
    private string $storeUuid;
    #[ORM\Column(length: 36)]
    private string $actorUuid;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $entryDate;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column(length: 8)]
    private string $direction;
    #[ORM\Column(length: 24)]
    private string $category;
    #[ORM\Column(type: 'bigint')]
    private int $amount;
    #[ORM\Column(length: 500)]
    private string $note;
    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $voidedBy = null;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $voidReason = null;

    /** @param array<string, mixed> $metadata */
    public function __construct(string $storeUuid, string $actorUuid, \DateTimeImmutable $entryDate, string $currency, string $direction, string $category, int $amount, string $note, array $metadata)
    {
        $this->uuid = UUID::v4();
        $this->storeUuid = $storeUuid;
        $this->actorUuid = $actorUuid;
        $this->entryDate = $entryDate;
        $this->currency = $currency;
        $this->direction = $direction;
        $this->category = $category;
        $this->amount = $amount;
        $this->note = $note;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function getActorUuid(): string { return $this->actorUuid; }
    public function getEntryDate(): \DateTimeImmutable { return $this->entryDate; }
    public function getCurrency(): string { return $this->currency; }
    public function getDirection(): string { return $this->direction; }
    public function getCategory(): string { return $this->category; }
    public function getAmount(): int { return $this->amount; }
    public function getNote(): string { return $this->note; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isVoided(): bool { return $this->voidedAt !== null; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getVoidedBy(): ?string { return $this->voidedBy; }
    public function getVoidReason(): ?string { return $this->voidReason; }

    public function void(string $actor, string $reason): void
    {
        if ($this->isVoided()) {
            throw new \LogicException('The store cash entry is already voided.');
        }
        $this->voidedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->voidedBy = $actor;
        $this->voidReason = $reason;
    }

    /** @return array<string, mixed> */
    public function correctionSnapshot(): array
    {
        return [
            'entry_date' => $this->entryDate->format('Y-m-d'), 'category' => $this->category,
            'direction' => $this->direction, 'amount' => (string) $this->amount,
            'note' => $this->note, 'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $metadata */
    public function correct(\DateTimeImmutable $date, string $category, string $direction, int $amount, string $note, array $metadata): void
    {
        $this->entryDate = $date;
        $this->category = $category;
        $this->direction = $direction;
        $this->amount = $amount;
        $this->note = $note;
        $this->metadata = $metadata;
    }

    public function __toString(): string { return $this->uuid; }
}
