<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Wallet\Entity\Transaction;

/** @extends BaseService<\App\Wallet\Entity\Transaction> */
final class TransactionService extends BaseService implements TransactionServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Transaction::class);
    }
}
