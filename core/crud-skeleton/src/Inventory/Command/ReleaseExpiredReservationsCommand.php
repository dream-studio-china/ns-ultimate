<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Service\InventoryServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:inventory:reservations:release-expired', description: 'Release expired confirmed inventory reservations.')]
final class ReleaseExpiredReservationsCommand extends Command
{
    public function __construct(private readonly InventoryServiceInterface $inventory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $released = $this->inventory->releaseExpiredReservations();
        $output->writeln(sprintf('Released %d expired Inventory reservation(s).', $released));

        return Command::SUCCESS;
    }
}
