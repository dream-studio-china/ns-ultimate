<?php

declare(strict_types=1);

namespace App\Trade\Command;

use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Message\TradeOrderCancelledMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:trade:outbox:publish', description: 'Publish pending Trade integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly TradeOutboxMessageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->repository->findUnpublished() as $message) {
            $id = $message->getId();
            if ($id === null || !$this->repository->claim($id, new \DateTimeImmutable('+1 minute'))) {
                continue;
            }
            if (!in_array($message->getTopic(), ['trade.order.created.v1', 'trade.order.cancelled.v1'], true)) {
                $this->repository->defer($id, 'Unsupported Trade outbox topic: ' . $message->getTopic(), new \DateTimeImmutable('+5 minutes'));
                continue;
            }
            $envelope = [
                'eventId' => $message->getEventId(),
                'type' => str_replace('.v1', '', $message->getTopic()),
                'version' => 1,
                'occurredAt' => $message->getOccurredAt()->format(DATE_ATOM),
                'aggregateType' => 'trade_order',
                'aggregateId' => $message->getAggregateId(),
                'correlationId' => $message->getAggregateId(),
                'causationId' => null,
                'payload' => $message->getPayload(),
            ];
            try {
                $this->messageBus->dispatch(match ($message->getTopic()) {
                    'trade.order.created.v1' => new TradeOrderCreatedMessage($envelope),
                    'trade.order.cancelled.v1' => new TradeOrderCancelledMessage($envelope),
                });
                $message->markPublished();
                ++$count;
            } catch (\Throwable $exception) {
                $this->repository->defer($id, $exception->getMessage(), new \DateTimeImmutable('+5 minutes'));
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Trade outbox message(s).', $count));

        return Command::SUCCESS;
    }
}
