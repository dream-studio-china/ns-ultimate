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

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $definition */
    public function __construct(string $name, string $kind, array $definition)
    {
        if (!in_array($kind, [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)) { throw new \InvalidArgumentException('Template kind must be receipt or label.'); }
        if (($definition['kind'] ?? null) !== $kind || !is_array($definition['nodes'] ?? null)) { throw new \InvalidArgumentException('Template definition kind and node list are required.'); }
        $this->uuid = UUID::v4();
        $this->name = trim($name);
        if ($this->name === '') { throw new \InvalidArgumentException('Template name cannot be empty.'); }
        $this->kind = $kind;
        $this->definition = $definition;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getName(): string { return $this->name; }
    public function getKind(): string { return $this->kind; }
    /** @return array<string, mixed> */ public function getDefinition(): array { return $this->definition; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
