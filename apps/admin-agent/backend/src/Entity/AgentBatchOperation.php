<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable pending batch proposal and minimal execution receipt; never exposed by generic CRUD. */
#[ORM\Entity]
#[ORM\Table(name: 'admin_agent_batch_operation')]
final class AgentBatchOperation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $actor;

    #[ORM\Column(type: 'string', length: 16)]
    private string $action;

    #[ORM\Column(type: 'string', length: 120)]
    private string $entityKey;

    #[ORM\Column(type: 'integer')]
    private int $rowCount;

    #[ORM\Column(type: 'string', length: 64)]
    private string $rowsHash;

    /** @var list<array<string, mixed>> ROWS is reserved in MySQL, so the physical name is explicit. */
    #[ORM\Column(name: 'proposal_rows', type: 'json')]
    private array $rows;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $fields;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'pending';

    /** @var list<array{row: int, identifier: string}> */
    #[ORM\Column(type: 'json')]
    private array $results = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(string $actor, string $entityKey, string $action, array $rows)
    {
        if ($rows === [] || !array_is_list($rows)) {
            throw new \InvalidArgumentException('A batch operation requires a non-empty canonical row list.');
        }
        $this->id = bin2hex(random_bytes(32));
        $this->actor = $actor;
        $this->entityKey = $entityKey;
        $this->action = $action;
        $this->rowCount = count($rows);
        $this->rowsHash = self::hashRows($rows);
        $this->rows = $rows;
        $fields = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $field) {
                if (!in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
        }
        $this->fields = $fields;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+5 minutes');
    }

    public function getId(): string { return $this->id; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getEntityKey(): string { return $this->entityKey; }
    public function getRowCount(): int { return $this->rowCount; }
    public function getRowsHash(): string { return $this->rowsHash; }
    /** @return list<array<string, mixed>> */
    public function getRows(): array { return $this->rows; }
    public function getStatus(): string { return $this->status; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    /** @param list<array{row: int, identifier: string}> $results */
    public function complete(array $results): void
    {
        $this->status = 'succeeded';
        $this->results = $results;
        $this->completedAt = new \DateTimeImmutable();
        // Receipts retain identifiers and field names, not proposal values or their digest.
        $this->rows = [];
        $this->rowsHash = self::hashRows([]);
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->rows = [];
        $this->rowsHash = self::hashRows([]);
        $this->results = [];
        $this->completedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        $status = $this->status === 'pending' && $this->expiresAt <= new \DateTimeImmutable() ? 'expired' : $this->status;

        return ['operationId' => $this->id, 'entity' => $this->entityKey, 'action' => $this->action,
            'status' => $status, 'rowCount' => $this->rowCount, 'fields' => $this->fields,
            'results' => $this->status === 'succeeded' ? $this->results : [],
            'createdAt' => $this->createdAt->format(DATE_ATOM), 'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'completedAt' => $this->completedAt?->format(DATE_ATOM)];
    }

    /** @param list<array<string, mixed>> $rows */
    public static function hashRows(array $rows): string
    {
        return hash('sha256', json_encode(self::canonicalize($rows), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
