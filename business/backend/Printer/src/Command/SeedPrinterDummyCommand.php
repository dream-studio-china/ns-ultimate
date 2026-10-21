<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Command;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:printer:seed-dummy', description: 'Seed offline Printer demo data (dev/test only; no provider calls)')]
final class SeedPrinterDummyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrintDocumentRenderer $renderer,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            $output->writeln('<error>Dummy Printer data is restricted to dev/test environments.</error>');
            return Command::FAILURE;
        }

        $account = $this->entityManager->getRepository(PrinterAccount::class)->findOneBy(['developerId' => 'local-printer-demo']);
        if (!$account instanceof PrinterAccount) {
            // Deliberately not encrypted credentials: this disabled account can never authenticate.
            $account = (new PrinterAccount('Offline demo account', 'local-printer-demo', ''))->setEnabled(false);
            $this->entityManager->persist($account);
        } elseif ($account->isEnabled() || $account->getCredentialsCipher() !== '') {
            $output->writeln('<error>The reserved demo account has been changed; refusing to modify it.</error>');
            return Command::FAILURE;
        }

        $data = ['demoTitle' => 'OFFLINE DEMO', 'order' => ['number' => 'DEMO-001', 'items' => [
            ['name' => 'Demo tea', 'quantity' => 2],
            ['name' => 'Demo cake', 'quantity' => 1],
        ]]];

        foreach ([PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL] as $kind) {
            $serial = 'LOCAL-DEMO-'.strtoupper($kind);
            $device = $account->getId() === null ? null : $this->entityManager->getRepository(PrinterDevice::class)->findOneBy(['account' => $account, 'serialNumber' => $serial]);
            if (!$device instanceof PrinterDevice) {
                $device = (new PrinterDevice($account, 'Offline demo '.$kind, $serial, $kind, null, 'Local demo', $kind === 'label' ? 50 : 80, $kind === 'label' ? 40 : null))->setEnabled(false);
                $this->entityManager->persist($device);
            } elseif ($device->isEnabled() || $device->getKind() !== $kind) {
                $output->writeln('<error>A reserved demo device has been changed; refusing to modify it.</error>');
                return Command::FAILURE;
            }

            $name = 'Offline demo '.$kind.' template';
            $template = $this->entityManager->getRepository(PrinterTemplate::class)->findOneBy(['name' => $name, 'kind' => $kind]);
            if (!$template instanceof PrinterTemplate) {
                $definition = $kind === PrinterDevice::KIND_RECEIPT ? [
                    'kind' => 'receipt',
                    'nodes' => [
                        ['type' => 'text', 'value' => 'OFFLINE DEMO - NOT PRINTED'], ['type' => 'line'],
                        ['type' => 'field', 'path' => 'order.number'], ['type' => 'line'],
                        ['type' => 'each', 'path' => 'order.items', 'children' => [
                            ['type' => 'field', 'path' => 'item.name'], ['type' => 'text', 'value' => ' x '],
                            ['type' => 'field', 'path' => 'item.quantity'], ['type' => 'line'],
                        ]],
                    ],
                ] : [
                    'kind' => 'label', 'widthMm' => 50, 'heightMm' => 40,
                    'nodes' => [['type' => 'each', 'path' => 'order.items', 'children' => [
                        ['type' => 'repeat', 'countPath' => 'item.quantity', 'children' => [
                            ['type' => 'labelText', 'x' => 8, 'y' => 8, 'path' => 'demoTitle'],
                            ['type' => 'labelText', 'x' => 8, 'y' => 40, 'path' => 'item.name'],
                        ]],
                    ]]],
                ];
                $template = new PrinterTemplate($name, $kind, $definition);
                $this->entityManager->persist($template);
            }

            $key = 'local-printer-demo-'.$kind.'-v1';
            if ($this->entityManager->getRepository(PrinterJob::class)->findOneBy(['idempotencyKey' => $key]) === null) {
                $document = $this->renderer->render($template->getDefinition(), $data);
                $job = new PrinterJob($device, 1, $template->getDefinition(), $data, $document['content'], $key);
                $job->failed('local_demo_not_submitted');
                $this->entityManager->persist($job);
            }
        }

        $this->entityManager->flush();
        $output->writeln('<info>Offline Printer demo data is ready: disabled account/devices, receipt/label templates and terminal sample jobs. No provider was contacted.</info>');
        return Command::SUCCESS;
    }
}
