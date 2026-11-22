<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Core\Utils\UUID;
use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;

#[ORM\Entity]
#[ORM\Table(name: 'ns_accounting_book', options: ['engine' => 'InnoDB', 'charset' => 'utf8mb4'])]
#[ORM\UniqueConstraint(name: 'uniq_accounting_book_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_acct_book_store', columns: ['store_uuid'])]
class AccountingBook
{
    #[ORM\Column(length: 36)]
    private string $uuid;
    public const ID = 1;

    #[ORM\Id, ORM\Column]
    private int $id;
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $storeUuid;
    #[ORM\Column(length: 160)]
    private string $entity;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column]
    private int $scale;
    #[ORM\Column(length: 80)]
    private string $timezone;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $openingDate;
    #[ORM\Column(length: 12)]
    private string $openingState = 'pending';
    #[ORM\Column(type: 'bigint')]
    private int $postingCount = 0;

    public function __construct(string $entity, string $currency, int $scale, string $timezone, \DateTimeImmutable $openingDate, int $id = self::ID, ?string $storeUuid = null)
    {
        $this->uuid = UUID::v4();
        $this->id = $id;
        $this->storeUuid = $storeUuid;
        if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new LedgerError('invalid_currency');
        }
        if ($scale < 0 || $scale > 6) {
            throw new LedgerError('invalid_scale');
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new LedgerError('invalid_timezone');
        }
        LedgerInput::date($openingDate->format('Y-m-d'));
        $this->entity = LedgerInput::text($entity, 160);
        $this->currency = $currency;
        $this->scale = $scale;
        $this->timezone = $timezone;
        $this->openingDate = $openingDate;
    }

    public function getId(): int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): ?string { return $this->storeUuid; }
    public function getEntity(): string { return $this->entity; }
    public function getCurrency(): string { return $this->currency; }
    public function getScale(): int { return $this->scale; }
    public function getTimezone(): string { return $this->timezone; }
    public function getOpeningDate(): \DateTimeImmutable { return $this->openingDate; }
    public function getOpeningState(): string { return $this->openingState; }
    public function getPostingCount(): int { return $this->postingCount; }
    public function __toString(): string { return $this->entity; }

    public function approveZeroOpening(): void
    {
        if ($this->openingState !== 'pending') {
            throw new LedgerError('opening_already_established', 409);
        }
        $this->openingState = 'zero';
    }

    public function assertCanPost(bool $opening): void
    {
        if ($opening !== ($this->openingState === 'pending')) {
            throw new LedgerError('opening_state_conflict', 409);
        }
    }

    public function recordPosting(bool $opening): int
    {
        $this->assertCanPost($opening);
        if ($this->postingCount === PHP_INT_MAX) {
            throw new LedgerError('series_exhausted', 409);
        }
        if ($opening) {
            $this->openingState = 'posted';
        }

        return ++$this->postingCount;
    }
}
