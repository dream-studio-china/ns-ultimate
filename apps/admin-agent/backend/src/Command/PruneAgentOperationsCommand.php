<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:admin-agent:prune', description: 'Erase expired assistant proposal values while retaining execution metadata.')]
final class PruneAgentOperationsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) $this->connection->executeStatement(
            'UPDATE admin_agent_operation SET changes = ?, status = ?, completed_at = ? WHERE status = ? AND expires_at <= ?',
            ['[]', 'expired', new \DateTimeImmutable(), 'pending', new \DateTimeImmutable()],
            ['string', 'string', 'datetime_immutable', 'string', 'datetime_immutable'],
        );
        $count += (int) $this->connection->executeStatement(
            'UPDATE admin_agent_batch_operation SET proposal_rows = ?, status = ?, completed_at = ? WHERE status = ? AND expires_at <= ?',
            ['[]', 'expired', new \DateTimeImmutable(), 'pending', new \DateTimeImmutable()],
            ['string', 'string', 'datetime_immutable', 'string', 'datetime_immutable'],
        );
        $output->writeln(sprintf('Erased %d expired proposal payload(s).', $count));
        return Command::SUCCESS;
    }
}
