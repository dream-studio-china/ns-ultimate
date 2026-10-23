<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:admin-agent:prune', description: 'Retire legacy assistant proposals and erase their values, retaining execution metadata.')]
final class PruneAgentOperationsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach (['admin_agent_operation' => 'changes', 'admin_agent_batch_operation' => 'proposal_rows'] as $table => $column) {
            if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }
            $count += (int) $this->connection->executeStatement(
                sprintf('UPDATE %s SET %s = ?, status = ?, completed_at = ? WHERE status = ?', $table, $column),
                ['[]', 'cancelled', new \DateTimeImmutable(), 'pending'],
                ['string', 'string', 'datetime_immutable', 'string'],
            );
        }
        $output->writeln(sprintf('Retired %d legacy proposal(s). Historical receipts were retained.', $count));
        return Command::SUCCESS;
    }
}
