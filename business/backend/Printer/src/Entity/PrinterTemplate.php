<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'printer_template')]
#[ORM\UniqueConstraint(name: 'uniq_printer_template_uuid', columns: ['uuid'])]
class PrinterTemplate
{
    public const TICKET_SINGLE = 'single';
    public const TICKET_PER_UNIT = 'per_unit';
    public const ITEMS_ALL = 'all';
    public const ITEMS_KITCHEN = 'kitchen';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $definition;

    /** Whether one request prints the whole order or one ticket per unit. */
    #[ORM\Column(name: 'ticket_mode', type: 'string', length: 20, options: ['default' => self::TICKET_SINGLE])]
    private string $ticketMode = self::TICKET_SINGLE;

    /** Which order items the request snapshot contains. */
    #[ORM\Column(name: 'item_filter', type: 'string', length: 20, options: ['default' => self::ITEMS_ALL])]
    private string $itemFilter = self::ITEMS_ALL;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $definition */
    public function __construct(string $name = '', string $kind = PrinterDevice::KIND_RECEIPT, array $definition = ['kind' => 'receipt', 'nodes' => []], string $ticketMode = self::TICKET_SINGLE, string $itemFilter = self::ITEMS_ALL)
    {
        if (!in_array($kind, [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)) { throw new \InvalidArgumentException('Template kind must be receipt or label.'); }
        if (($definition['kind'] ?? null) !== $kind || !is_array($definition['nodes'] ?? null)) { throw new \InvalidArgumentException('Template definition kind and node list are required.'); }
        $this->uuid = UUID::v4();
        $this->name = trim($name);
        $this->kind = $kind;
        $this->definition = $definition;
        $this->setTicketMode($ticketMode);
        $this->setItemFilter($itemFilter);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self
    {
        $name = trim($name);
        if ($name === '') { throw new \InvalidArgumentException('Template name cannot be empty.'); }
        $this->name = $name;
        return $this;
    }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): self
    {
        if (!in_array($kind, [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)) { throw new \InvalidArgumentException('Template kind must be receipt or label.'); }
        $this->kind = $kind;
        return $this;
    }
    /** @return array<string, mixed> */ public function getDefinition(): array { return $this->definition; }
    /** @param array<string, mixed> $definition */
    public function setDefinition(array $definition): self
    {
        if (($definition['kind'] ?? null) !== $this->kind || !is_array($definition['nodes'] ?? null)) { throw new \InvalidArgumentException('Template definition kind and node list are required.'); }
        $this->definition = $definition;
        return $this;
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getTicketMode(): string { return $this->ticketMode; }
    public function setTicketMode(string $ticketMode): self
    {
        if (!in_array($ticketMode, [self::TICKET_SINGLE, self::TICKET_PER_UNIT], true)) {
            throw new \InvalidArgumentException('Template ticket mode must be single or per_unit.');
        }
        $this->ticketMode = $ticketMode;
        return $this;
    }
    public function getItemFilter(): string { return $this->itemFilter; }
    public function setItemFilter(string $itemFilter): self
    {
        if (!in_array($itemFilter, [self::ITEMS_ALL, self::ITEMS_KITCHEN], true)) {
            throw new \InvalidArgumentException('Template item filter must be all or kitchen.');
        }
        $this->itemFilter = $itemFilter;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function __toString(): string { return $this->name !== '' ? $this->name : $this->uuid; }
}
