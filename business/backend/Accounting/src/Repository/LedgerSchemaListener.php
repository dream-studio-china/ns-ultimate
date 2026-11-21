<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Repository;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Keep the same-book composite FKs without mapping overlapping join columns.
 * Doctrine treats book_id as non-null in those rows even for an optional reversal.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class LedgerSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if ($schema->hasTable('ns_accounting_journal')) {
            $schema->getTable('ns_accounting_journal')
                ->addForeignKeyConstraint('ns_accounting_journal', ['book_id', 'reversal_of'], ['book_id', 'id']);
        }
        if ($schema->hasTable('ns_accounting_line')) {
            $line = $schema->getTable('ns_accounting_line');
            if ($schema->hasTable('ns_accounting_journal')) {
                $line->addForeignKeyConstraint('ns_accounting_journal', ['book_id', 'journal_id'], ['book_id', 'id']);
            }
            if ($schema->hasTable('ns_accounting_account')) {
                $line->addForeignKeyConstraint('ns_accounting_account', ['book_id', 'account_code'], ['book_id', 'code']);
            }
        }
    }
}
