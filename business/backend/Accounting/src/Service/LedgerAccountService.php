<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Accounting\Entity\AccountingBook;
use NsUltimate\Business\Accounting\Entity\LedgerAccount;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<LedgerAccount> */
final class LedgerAccountService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, LedgerAccount::class);
    }

    /** @return list<array<string, int|string>> */
    public function chart(): array
    {
        $accounts = $this->list(['book' => AccountingBook::ID], ['entity.code' => 'ASC']);

        return array_values(array_map($this->project(...), $accounts));
    }

    /** @return array<string, int|string>|null */
    public function record(string $code): ?array
    {
        $account = $this->get(['book' => AccountingBook::ID, 'code' => $code], false);

        return $account === null ? null : $this->project($account);
    }

    /** @return array<string, int|string> */
    private function project(LedgerAccount $account): array
    {
        return [
            'book_id' => $account->getBook()->getId(), 'code' => $account->getCode(), 'name' => $account->getName(),
            'classification' => $account->getClassification(), 'normal_side' => $account->getNormalSide(),
            'active' => (int) $account->isActive(), 'postable' => (int) $account->isPostable(),
        ];
    }

    public function new(): never { throw new \LogicException('Use the ledger commands to create an account.'); }

    /** @param array<string, mixed>|null $data */
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): never
    {
        throw new \LogicException('Use the ledger commands to update an account.');
    }

    public function remove($object): never { throw new \LogicException('Ledger accounts cannot be deleted.'); }
}
