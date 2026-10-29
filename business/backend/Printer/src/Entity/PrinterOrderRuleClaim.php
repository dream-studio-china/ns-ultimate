<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Inserted atomically through DBAL in the same transaction as the order event. */
#[ORM\Entity]
#[ORM\Table(name: 'printer_order_rule_claim')]
class PrinterOrderRuleClaim
{
    #[ORM\Id, ORM\Column(name: 'claim_key', type: 'string', length: 64)]
    private string $claimKey;

    #[ORM\Column(type: 'string', length: 36)]
    private string $token;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
}
