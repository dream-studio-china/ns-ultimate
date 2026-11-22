<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Accounting\Migrations\Version20261122100000;
use NsUltimate\Business\Accounting\Migrations\Version20261122160000;
use NsUltimate\Business\Accounting\Service\LedgerCommands;
use NsUltimate\Business\Accounting\Repository\LedgerWriteRepository;
use NsUltimate\Integration\Backend\Kernel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StoreCashApiTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    public function testStoreScopeRetryMonthlySummaryAndUnitCost(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $schemaDb = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__).'/migrations/Version20261122100000.php';
        $migration = new Version20261122100000($schemaDb, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $schemaDb->executeStatement($sql->getStatement());
        }
        require_once dirname(__DIR__).'/migrations/Version20261122160000.php';
        $voidMigration = new Version20261122160000($schemaDb, new NullLogger());
        $voidMigration->up(new Schema());
        foreach ($voidMigration->getSql() as $sql) {
            $schemaDb->executeStatement($sql->getStatement());
        }
        $table = 'ns_store_cash_entry';
        $diff = $schemaDb->createSchemaManager()->createComparator()->compareTables(
            $schemaDb->createSchemaManager()->introspectTable($table),
            $em->getConnection()->createSchemaManager()->introspectTable($table),
        );
        self::assertTrue($diff->isEmpty());
        $seed = new CommandTester((new Application(self::$kernel))->find('app:accounting:seed-authorization'));
        self::assertSame(0, $seed->execute([]));
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'store_cash_recorder']);
        $reader = $em->getRepository(Role::class)->findOneBy(['code' => 'store_cash_reader']);
        $store = new Store('cash-test', 'Cash Test');
        $other = new Store('other-cash-test', 'Other Cash Test');
        $staff = (new User())->setUsername('cash-staff')->setEmail('cash-staff@example.test');
        $readOnly = (new User())->setUsername('cash-reader')->setEmail('cash-reader@example.test');
        $noMember = (new User())->setUsername('cash-no-member')->setEmail('cash-no-member@example.test');
        foreach ([$store, $other, $staff, $readOnly, $noMember,
            new Membership($store, $staff->getUuid(), Membership::ROLE_MANAGER),
            new Membership($store, $readOnly->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $staff->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()),
            new Assignment($reader, $readOnly->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()),
            new Assignment($role, $noMember->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()),
        ] as $record) {
            $em->persist($record);
        }
        $em->flush();
        $commands = new LedgerCommands(new LedgerWriteRepository($em));
        $bookId = 20;
        $commands->execute('cash-test-actor', 'initialize', [
            'entity' => 'Cash Test Book', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'UTC', 'opening_date' => '2026-01-01',
        ], $bookId, $store->getUuid());
        foreach ([
            ['category' => 'purchase', 'direction' => 'expense', 'name' => 'Purchase'],
            ['category' => 'other_income', 'direction' => 'income', 'name' => 'Other income'],
        ] as $category) {
            $commands->execute('cash-test-actor', 'create-cash-category', $category, $bookId);
        }
        $tokens = self::getContainer()->get(TokenManager::class);
        $token = $tokens->createAccessToken($staff);
        $readToken = $tokens->createAccessToken($readOnly);
        $noMemberToken = $tokens->createAccessToken($noMember);
        $purchase = [
            'commandUuid' => UUID::v4(), 'date' => '2026-11-01', 'direction' => 'expense', 'category' => 'purchase', 'amount' => '12000',
            'metadata' => ['version' => 1, 'item' => 'Coffee beans', 'quantity' => '2', 'unit' => 'kg', 'extra' => ['vendor_note' => 'Fixture']],
        ];
        $storeId = $store->getUuid();
        self::assertSame(401, $this->call($storeId, null)->getStatusCode());
        self::assertSame(403, $this->call($other->getUuid(), $token, $purchase)->getStatusCode());
        self::assertSame(403, $this->call($storeId, $noMemberToken)->getStatusCode());
        self::assertSame(403, $this->call($storeId, $readToken, $purchase)->getStatusCode());
        self::assertSame(400, $this->call($storeId, $token, $purchase + ['storeUuid' => $other->getUuid()])->getStatusCode());
        self::assertSame(400, $this->call($storeId, $token, array_replace_recursive($purchase, ['metadata' => ['unit' => 'bad']]))->getStatusCode());
        self::assertSame(400, $this->call($storeId, $token, array_replace_recursive($purchase, ['metadata' => ['unitPrice' => '1']]))->getStatusCode());
        self::assertSame(['purchase', 'other_income'], array_column($this->data($this->call($storeId, $readToken, null, '/categories'))['categories'], 'id'));
        $first = $this->data($this->call($storeId, $token, $purchase));
        self::assertSame($first, $this->data($this->call($storeId, $token, $purchase)));
        self::assertSame(409, $this->call($storeId, $token, array_replace($purchase, ['amount' => '1']))->getStatusCode());
        $this->data($this->call($storeId, $token, [
            'commandUuid' => UUID::v4(), 'date' => '2026-11-08', 'direction' => 'income', 'category' => 'other_income', 'amount' => '200',
        ]));
        $month = $this->data($this->call($storeId, $readToken));
        self::assertSame([['currency' => $store->getCurrency(), 'income' => '200', 'expense' => '12000', 'byCategory' => ['purchase' => '12000', 'other_income' => '200']]], $month['totals']);
        self::assertCount(2, $month['entries']);
        self::assertSame(['baseUnit' => 'g', 'baseQuantity' => '2000', 'unitPrice' => '0.06'], $month['entries'][1]['purchaseCost']);
        self::assertSame('manual', $month['entries'][1]['source']);
        self::assertSame('Fixture', $month['entries'][1]['metadata']['extra']['vendor_note']);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM ns_accounting_journal'));
    }

    private function call(string $store, ?string $token, ?array $body = null, string $path = ''): Response
    {
        return self::$kernel->handle(Request::create(
            '/api/v1/store/'.$store.'/cash-register'.$path.($body === null && $path === '' ? '?month=2026-11' : ''),
            $body === null ? 'GET' : 'POST', server: [
                'CONTENT_TYPE' => 'application/json', ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
