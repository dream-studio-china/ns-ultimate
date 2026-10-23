<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Counter batch creation reuses the single-record policy and requires one explicit existing Store. */
final class CounterBatchCreateAdapter extends DelegatingBatchCreateAdapter
{
    public function __construct(CounterMutationAdapter $inner)
    {
        parent::__construct($inner);
    }

    protected function identity(array $changes): string
    {
        // The counter store/code unique index uses a case-insensitive collation.
        return 'store:'.$changes['storeUuid'].'|code:'.mb_strtolower($changes['code'], 'UTF-8');
    }

    protected function assertBatch(array $rows): void
    {
        $stores = [];
        foreach ($rows as $row) {
            $stores[$row['storeUuid']] = true;
        }
        if (count($stores) !== 1) {
            throw new BadRequestHttpException('A Counter batch must use one explicit existing Store.');
        }
    }
}
