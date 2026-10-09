<?php

declare(strict_types=1);

namespace App\Wallet\Repository;

use App\Wallet\Entity\VoucherComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Wallet\Entity\VoucherComment>
 */
class VoucherCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoucherComment::class);
    }
}
