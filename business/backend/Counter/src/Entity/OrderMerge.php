<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'counter_order_merge')]
#[ORM\UniqueConstraint(name: 'uniq_counter_order_merge_source', columns: ['source_order_uuid'])]
#[ORM\Index(name: 'idx_counter_order_merge_target', columns: ['target_order_uuid'])]
class OrderMerge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'source_order_uuid', type: 'string', length: 36)]
    private string $sourceOrderUuid;

    #[ORM\Column(name: 'target_order_uuid', type: 'string', length: 36)]
    private string $targetOrderUuid;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $sourceOrderUuid, string $targetOrderUuid)
    {
        $this->sourceOrderUuid = $sourceOrderUuid;
        $this->targetOrderUuid = $targetOrderUuid;
        $this->createdAt = new \DateTimeImmutable();
    }
}
