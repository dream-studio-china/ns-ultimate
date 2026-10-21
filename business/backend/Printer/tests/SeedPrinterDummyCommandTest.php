<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Command\SeedPrinterDummyCommand;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedPrinterDummyCommandTest extends TestCase
{
    public function testRefusesProductionBeforeAccessingDatabase(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $tester = new CommandTester(new SeedPrinterDummyCommand($entityManager, new PrintDocumentRenderer(), 'prod'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('restricted to dev/test', $tester->getDisplay());
    }

    public function testSeedsIdempotentOfflineData(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The in-memory persistence test requires pdo_sqlite.');
        }
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $configuration);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $tester = new CommandTester(new SeedPrinterDummyCommand($entityManager, new PrintDocumentRenderer(), 'test'));

        try {
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            $entityManager->clear();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            $entityManager->clear();

            self::assertSame(1, $entityManager->getRepository(PrinterAccount::class)->count([]));
            self::assertSame(2, $entityManager->getRepository(PrinterDevice::class)->count([]));
            self::assertSame(2, $entityManager->getRepository(PrinterTemplate::class)->count([]));
            self::assertSame(2, $entityManager->getRepository(PrinterJob::class)->count([]));

            $account = $entityManager->getRepository(PrinterAccount::class)->findOneBy([]);
            self::assertFalse($account->isEnabled());
            self::assertSame('', $account->getCredentialsCipher());
            foreach ($entityManager->getRepository(PrinterDevice::class)->findAll() as $device) {
                self::assertFalse($device->isEnabled());
                self::assertNull($device->getProviderStatus());
            }
            foreach ($entityManager->getRepository(PrinterTemplate::class)->findAll() as $template) {
                $document = (new PrintDocumentRenderer())->render($template->getDefinition(), ['order' => ['number' => 'TEST', 'items' => [['name' => 'Tea', 'quantity' => 2]]]]);
                self::assertNotEmpty($document['content']);
                self::assertCount($template->getKind() === 'label' ? 2 : 1, $document['pages']);
            }
            foreach ($entityManager->getRepository(PrinterJob::class)->findAll() as $job) {
                self::assertSame(PrinterJob::STATUS_FAILED, $job->getStatus());
                self::assertSame('local_demo_not_submitted', $job->getErrorCode());
                self::assertNull($job->getProviderOrderId());
                self::assertStringContainsString('OFFLINE DEMO', $job->getRenderedContent());
                self::assertStringContainsString('Demo tea', $job->getRenderedContent());
            }

            $account->setEnabled(true);
            $entityManager->flush();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertTrue($account->isEnabled(), 'Existing changes must not be overwritten.');
        } finally {
            $entityManager->getConnection()->close();
        }
    }
}
