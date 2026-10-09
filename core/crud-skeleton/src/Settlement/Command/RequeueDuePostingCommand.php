<?php
declare(strict_types=1);

namespace App\Settlement\Command;

use App\Settlement\Repository\SettlementAllocationRepository;
use App\Settlement\Service\SettlementOutboxService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:settlement:allocations:requeue-due', description: 'Requeue retryable Settlement allocation postings.')]
final class RequeueDuePostingCommand extends Command
{
    public function __construct(private readonly SettlementAllocationRepository $repository, private readonly SettlementOutboxService $outbox, private readonly EntityManagerInterface $entityManager) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->repository->findRetryableDue() as $allocation) {
            $id = $allocation->getId();
            if ($id === null || !$this->repository->claimRetryDue($id)) continue;
            $this->outbox->record('settlement.allocation.post.requested.v1', 'settlement_allocation', $allocation->getUuid(), ['allocationUuid' => $allocation->getUuid(), 'planUuid' => $allocation->getPlanUuid()]);
            ++$count;
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Requeued %d Settlement allocation(s).', $count));
        return Command::SUCCESS;
    }
}
