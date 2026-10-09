<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Message\ReservationConfirmedMessage;
use App\Inventory\Message\ReservationRejectedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:inventory:outbox:publish', description: 'Publish pending Inventory integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly InventoryOutboxMessageRepository $repository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = 0;
        foreach ($this->repository->findUnpublishedForPublishing() as $message) {
            if (!$this->repository->claim($message['id'], new \DateTimeImmutable('+1 minute'))) {
                continue;
            }
            $envelope = [
                'eventId' => $message['eventId'],
                'type' => str_replace('.v1', '', $message['topic']),
                'version' => 1,
                'aggregateId' => $message['aggregateId'],
                'payload' => $message['payload'],
            ];
            $busMessage = match ($message['topic']) {
                'inventory.reservation.confirmed.v1' => new ReservationConfirmedMessage($envelope),
                'inventory.reservation.rejected.v1' => new ReservationRejectedMessage($envelope),
                'inventory.reservation.released.v1' => new ReservationReleasedMessage($envelope),
                default => null,
            };
            if ($busMessage === null) {
                $this->repository->recordAttempt(
                    $message['id'],
                    'Unsupported Inventory outbox topic: ' . $message['topic'],
                    new \DateTimeImmutable('+5 minutes'),
                );
                continue;
            }

            try {
                $this->messageBus->dispatch($busMessage);
                $this->repository->markPublished($message['id']);
                ++$published;
            } catch (\Throwable $exception) {
                $this->repository->recordAttempt(
                    $message['id'],
                    $exception->getMessage(),
                    new \DateTimeImmutable('+5 minutes'),
                );
            }
        }
        $output->writeln(sprintf('Published %d Inventory outbox message(s).', $published));

        return Command::SUCCESS;
    }
}
