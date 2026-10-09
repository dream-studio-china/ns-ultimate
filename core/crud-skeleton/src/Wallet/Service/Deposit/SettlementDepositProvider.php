<?php
declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Wallet\Entity\Voucher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SettlementDepositProvider implements DepositProviderInterface
{
    public function __construct(private readonly Security $security) {}
    public static function getName(): string { return 'settlement'; }
    public function supports(string $voucherType): bool { return $voucherType === 'settlement'; }
    public function assertPermitted(array $options = []): void
    {
        if ($this->security->getToken() !== null) throw new AccessDeniedException('Settlement vouchers are internal only.');
    }
    public function authorize(Voucher $voucher, array $options): void {}
    public function reverse(Voucher $voucher, string $reason, array $options = []): void {}
}
