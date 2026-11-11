<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Service;

use App\Promotion\Entity\Promotion;

/**
 * In-memory campaign identity for stack simulation. Never persisted; the
 * negative identifier only lets the existing calculator de-duplicate a draft.
 */
final class SimulatedPromotion extends Promotion
{
    public function __construct(private readonly int $simulatedId)
    {
        parent::__construct();
    }

    public function getId(): int
    {
        return $this->simulatedId;
    }
}
