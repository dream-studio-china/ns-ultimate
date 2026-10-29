<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use App\Core\Utils\UUID;
use Doctrine\ORM\Mapping as ORM;

/** Store-owned automatic printing configuration. UUID remains stable across edits. */
#[ORM\Entity]
#[ORM\Table(name: 'printer_order_rule')]
#[ORM\UniqueConstraint(name: 'uniq_printer_order_rule_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_printer_order_rule_store_enabled', columns: ['store_uuid', 'enabled'])]
class PrinterOrderRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'store_uuid', type: 'string', length: 36)]
    private string $storeUuid = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = false;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $events = [];

    #[ORM\Column(name: 'match_mode', type: 'string', length: 3)]
    private string $match = 'all';

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $conditions = [];

    #[ORM\Column(type: 'string', length: 20)]
    private string $scene = '';

    #[ORM\Column(name: 'device_uuid', type: 'string', length: 36)]
    private string $deviceUuid = '';

    #[ORM\Column(name: 'template_uuid', type: 'string', length: 36)]
    private string $templateUuid = '';

    #[ORM\Column(type: 'integer')]
    private int $copies = 1;

    public function __construct() { $this->uuid = UUID::v4(); }
    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getStoreUuid(): string { return $this->storeUuid; }
    public function setStoreUuid(string $value): self { $this->storeUuid = $value; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $value): self { $this->name = $value; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $value): self { $this->enabled = $value; return $this; }
    /** @return list<string> */ public function getEvents(): array { return $this->events; }
    /** @param list<string> $value */ public function setEvents(array $value): self { $this->events = $value; return $this; }
    public function getMatch(): string { return $this->match; }
    public function setMatch(string $value): self { $this->match = $value; return $this; }
    /** @return list<array<string, mixed>> */ public function getConditions(): array { return $this->conditions; }
    /** @param list<array<string, mixed>> $value */ public function setConditions(array $value): self { $this->conditions = $value; return $this; }
    public function getScene(): string { return $this->scene; }
    public function setScene(string $value): self { $this->scene = $value; return $this; }
    public function getDeviceUuid(): string { return $this->deviceUuid; }
    public function setDeviceUuid(string $value): self { $this->deviceUuid = $value; return $this; }
    public function getTemplateUuid(): string { return $this->templateUuid; }
    public function setTemplateUuid(string $value): self { $this->templateUuid = $value; return $this; }
    public function getCopies(): int { return $this->copies; }
    public function setCopies(int $value): self { $this->copies = $value; return $this; }

    /** @return array<string, mixed> */
    public function toRule(): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'enabled' => $this->enabled,
            'events' => $this->events, 'match' => $this->match, 'conditions' => $this->conditions,
            'scene' => $this->scene, 'deviceUuid' => $this->deviceUuid,
            'templateUuid' => $this->templateUuid, 'copies' => $this->copies];
    }
}
