<?php
declare(strict_types=1);

namespace App\Settlement\Command;

use App\Settlement\Message\SettlementAllocationPostingMessage;
use App\Settlement\Repository\SettlementOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:settlement:outbox:publish', description: 'Publish pending Settlement integration events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(private readonly SettlementOutboxMessageRepository $repository, private readonly EntityManagerInterface $entityManager, private readonly MessageBusInterface $messageBus) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->repository->findUnpublished() as $message) {
            $id = $message->getId();
            if ($id === null || !$this->repository->claim($id, new \DateTimeImmutable('+1 minute'))) continue;
            try {
                $payload = $message->getPayload();
                if ($message->getTopic() !== 'settlement.allocation.post.requested.v1') throw new \InvalidArgumentException('Unsupported Settlement outbox topic: ' . $message->getTopic());
                $this->messageBus->dispatch(new SettlementAllocationPostingMessage((string) ($payload['allocationUuid'] ?? ''), (string) ($payload['planUuid'] ?? '')));
                $message->markPublished();
                ++$count;
            } catch (\Throwable $exception) {
                $this->repository->defer($id, $exception->getMessage(), new \DateTimeImmutable('+5 minutes'));
            }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Published %d Settlement outbox message(s).', $count));
        return Command::SUCCESS;
    }
}
