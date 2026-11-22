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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Accounting\Entity\StoreCashEntry;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StoreLedgerApiTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    public function testStoreReaderCannotCrossBooks(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $seed = new CommandTester((new Application(self::$kernel))->find('app:accounting:seed-authorization'));
        self::assertSame(0, $seed->execute([]));
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'store_accounting_reader']);
        $cashRole = $em->getRepository(Role::class)->findOneBy(['code' => 'accounting_cash_recorder']);
        $cashReader = $em->getRepository(Role::class)->findOneBy(['code' => 'store_cash_reader']);
        $a = new Store('ledger-a', 'Ledger A');
        $b = new Store('ledger-b', 'Ledger B');
        $c = new Store('ledger-c', 'Ledger C');
        $manager = (new User())->setUsername('ledger-manager')->setEmail('ledger-manager@example.test');
        $sharedManager = (new User())->setUsername('shared-manager')->setEmail('shared-manager@example.test');
        $head = (new User())->setUsername('ledger-head')->setEmail('ledger-head@example.test')->setRoles(['ROLE_ADMIN']);
        $recorder = (new User())->setUsername('ledger-recorder')->setEmail('ledger-recorder@example.test');
        foreach ([$a, $b, $c, $manager, $sharedManager, $head, $recorder, new Membership($a, $manager->getUuid(), Membership::ROLE_MANAGER),
            new Membership($b, $manager->getUuid(), Membership::ROLE_MANAGER),
            new Membership($c, $sharedManager->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $sharedManager->getUuid(), Assignment::SCOPE_STORE, $c->getUuid()),
            new Assignment($cashReader, $sharedManager->getUuid(), Assignment::SCOPE_STORE, $c->getUuid()),
            new Assignment($cashRole, $recorder->getUuid(), Assignment::SCOPE_GLOBAL, null),
            new Assignment($role, $manager->getUuid(), Assignment::SCOPE_STORE, $a->getUuid())] as $record) {
            $em->persist($record);
        }
        $em->flush();
        $tokens = self::getContainer()->get(TokenManager::class);
        $headToken = $tokens->createAccessToken($head);
        $managerToken = $tokens->createAccessToken($manager);
        $finance = '/finance/accounting';
        $initial = ['entity' => 'Fixture ledger', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'UTC', 'opening_date' => '2026-01-01'];
        $this->data($this->call($finance.'/books', $headToken, $initial));
        $journals = [];
        $books = [];
        foreach ([$a, $b] as $i => $store) {
            $params = $initial + ['store_uuid' => $store->getUuid()];
            $book = $this->data($this->call($finance.'/books', $headToken, $params));
            self::assertSame($book['id'], $this->data($this->call($finance.'/books', $headToken, $params))['id']);
            $path = $finance.'/book/'.$book['id'];
            $books[] = $path;
            self::assertSame($store->getUuid(), $this->data($this->call($path.'/workspace', $headToken))['book']['store_uuid']);
            foreach (['CASH' => ['asset', 'debit'], 'EQUITY' => ['equity', 'credit']] as $code => [$class, $side]) {
                $this->data($this->call($path.'/ledger-accounts', $headToken, [
                    'code' => $code, 'name' => $code, 'classification' => $class, 'normal_side' => $side, 'active' => true, 'postable' => true,
                ]));
            }
            $this->data($this->call($path.'/accounting-periods', $headToken, ['month' => '2026-01']));
            $this->data($this->call($path.'/commands/approve-zero-opening', $headToken, ['reason' => 'Fixture', 'command_id' => UUID::v4()]));
            $amount = (string) ($i + 1);
            $journal = $this->data($this->call($path.'/journals', $headToken, [
                'origin' => 'manual', 'summary' => 'Fixture book '.$i, 'accounting_date' => '2026-01-01', 'evidence' => 'Restricted',
                'attachments' => ['https://example.test/fixture.pdf'], 'metadata' => ['ref' => 'restricted'],
                'lines' => [['account_code' => 'CASH', 'side' => 'debit', 'amount' => $amount],
                            ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => $amount]],
            ]));
            $journals[] = $journal['id'];
            self::assertSame('2026-1', $this->data($this->call($path.'/commands/post', $headToken, [
                'id' => $journal['id'], 'revision' => $journal['revision'], 'command_id' => UUID::v4(),
            ]))['number']);
        }
        $own = '/store/'.$a->getUuid().'/accounting';
        $other = '/store/'.$b->getUuid().'/accounting';
        $range = '?from=2026-01-01&to=2026-01-31&account=CASH';
        self::assertSame($a->getUuid(), $this->data($this->call($own.'/workspace', $managerToken))['book']['store_uuid']);
        self::assertSame([$journals[0]], array_column($this->data($this->call($own.'/journals', $managerToken)), 'id'));
        $detail = $this->data($this->call($own.'/journals/'.$journals[0], $managerToken));
        foreach (['metadata', 'evidence', 'attachments'] as $field) {
            self::assertArrayNotHasKey($field, $detail);
        }
        self::assertSame(404, $this->call($own.'/journals/'.$journals[1], $managerToken)->getStatusCode());
        self::assertSame(403, $this->call($other.'/journals', $managerToken)->getStatusCode());
        self::assertSame(403, $this->call($other.'/reports/ledger/csv'.$range, $managerToken)->getStatusCode());
        self::assertSame(400, $this->call($own.'/reports/ledger/csv'.$range.'&book_id=1', $managerToken)->getStatusCode());
        self::assertSame(403, $this->call($finance.'/workspace', $managerToken)->getStatusCode());
        self::assertSame([], $this->data($this->call($finance.'/journals', $headToken)));
        self::assertSame('1', $this->data($this->call($own.'/reports/ledger'.$range, $managerToken))[0]['amount']);
        self::assertSame('1', $this->data($this->call($own.'/reports/trial?from=2026-01-01&to=2026-01-31', $managerToken))[0]['closing_debit']);
        $csv = $this->call($own.'/reports/ledger/csv'.$range, $managerToken);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString($journals[0], $csv->getContent());
        self::assertStringNotContainsString($journals[1], $csv->getContent());
        self::assertSame(405, $this->call($own.'/journals', $managerToken, [])->getStatusCode());

        $shared = $books[0];
        $sharedBookId = (int) basename($shared);
        foreach ([
            ['purchase', 'expense', 'Purchase'],
            ['transport', 'expense', 'Transport'],
            ['maintenance', 'expense', 'Maintenance'],
            ['other_expense', 'expense', 'Other expense'],
            ['other_income', 'income', 'Other income'],
        ] as [$category, $direction, $name]) {
            $em->getConnection()->insert('ns_accounting_cash_category', [
                'uuid' => UUID::v4(), 'book_id' => $sharedBookId, 'category' => $category, 'direction' => $direction, 'name' => $name,
            ]);
        }
        $sharedReader = $tokens->createAccessToken($sharedManager);
        $sharedStore = '/store/'.$c->getUuid().'/accounting';
        self::assertSame(404, $this->call($sharedStore.'/workspace', $sharedReader)->getStatusCode());
        self::assertSame(403, $this->call($finance.'/available-stores', $managerToken)->getStatusCode());
        $available = $this->data($this->call($finance.'/available-stores?q=LEDGER', $headToken));
        self::assertSame([['uuid' => $c->getUuid(), 'code' => 'ledger-c', 'name' => 'Ledger C']], $available['stores']);
        self::assertSame([], $this->data($this->call($finance.'/available-stores?q=%25', $headToken))['stores']);
        self::assertSame(409, $this->call($shared.'/commands/bind-store', $headToken, ['store_uuid' => $b->getUuid()])->getStatusCode());
        $binding = ['store_uuid' => $c->getUuid()];
        self::assertSame($binding, $this->data($this->call($shared.'/commands/bind-store', $headToken, $binding)));
        self::assertSame($binding, $this->data($this->call($shared.'/commands/bind-store', $headToken, $binding)));
        self::assertSame([], $this->data($this->call($finance.'/available-stores?q=ledger-c', $headToken))['stores']);
        self::assertSame(409, $this->call($finance.'/books', $headToken, $initial + $binding)->getStatusCode());
        self::assertSame($c->getUuid(), $this->data($this->call($sharedStore.'/workspace', $sharedReader))['book']['store_uuid']);

        $draft = ['origin' => 'manual', 'summary' => 'Shared ledger fixture', 'accounting_date' => '2026-01-05',
            'lines' => [['account_code' => 'CASH', 'side' => 'debit', 'amount' => '7'], ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => '7']]];
        self::assertSame(409, $this->call($shared.'/journals', $headToken, $draft + ['store_uuid' => $b->getUuid()])->getStatusCode());
        $cJournal = $this->data($this->call($shared.'/journals', $headToken, $draft + $binding));
        $hqJournal = $this->data($this->call($shared.'/journals', $headToken, $draft));
        self::assertSame($c->getUuid(), $cJournal['store_uuid']);
        self::assertNull($hqJournal['store_uuid']);
        foreach ([$cJournal, $hqJournal] as $offset => $entry) {
            self::assertSame('2026-'.($offset + 2), $this->data($this->call($shared.'/commands/post', $headToken, [
                'id' => $entry['id'], 'revision' => $entry['revision'], 'command_id' => UUID::v4(),
            ]))['number']);
        }
        self::assertCount(3, $this->data($this->call($shared.'/journals', $headToken)));
        self::assertSame([$journals[0]], array_column($this->data($this->call($own.'/journals', $managerToken)), 'id'));
        self::assertSame([$cJournal['id']], array_column($this->data($this->call($sharedStore.'/journals', $sharedReader)), 'id'));
        self::assertSame(403, $this->call($sharedStore.'/reports/ledger/csv'.$range, $managerToken)->getStatusCode());
        self::assertSame(404, $this->call($own.'/journals/'.$cJournal['id'], $managerToken)->getStatusCode());
        self::assertSame(404, $this->call($sharedStore.'/journals/'.$hqJournal['id'], $sharedReader)->getStatusCode());
        $later = '?from=2026-01-06&to=2026-01-31';
        $trial = $this->data($this->call($own.'/reports/trial'.$later, $managerToken));
        self::assertSame('1', $trial[0]['opening_debit']);
        $trial = $this->data($this->call($sharedStore.'/reports/trial'.$later, $sharedReader));
        self::assertSame('7', $trial[0]['opening_debit']);
        $csv = $this->call($sharedStore.'/reports/ledger/csv'.$range, $sharedReader);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString($cJournal['id'], $csv->getContent());
        self::assertStringNotContainsString($journals[0], $csv->getContent());
        self::assertStringNotContainsString($hqJournal['id'], $csv->getContent());

        $reversed = $this->data($this->call($shared.'/commands/reverse', $headToken, [
            'id' => $cJournal['id'], 'accounting_date' => '2026-01-07', 'reason' => 'Fixture reversal', 'command_id' => UUID::v4(),
        ]));
        self::assertSame($c->getUuid(), $this->data($this->call($sharedStore.'/journals/'.$reversed['id'], $sharedReader))['store_uuid']);
        self::assertSame(404, $this->call($own.'/journals/'.$reversed['id'], $managerToken)->getStatusCode());

        $cash = new StoreCashEntry($c->getUuid(), $sharedManager->getUuid(), new \DateTimeImmutable('2026-01-08'), 'CNY', 'expense', 'transport', 12000, 'Freight', []);
        $foreignCash = new StoreCashEntry($b->getUuid(), $manager->getUuid(), new \DateTimeImmutable('2026-01-08'), 'CNY', 'expense', 'transport', 30, '', []);
        foreach ([$cash, $foreignCash] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $source = ['source_uuid' => $cash->getUuid()];
        self::assertSame([$cash->getUuid()], array_column($this->data($this->call($shared.'/cash-entries?category=transport', $headToken)), 'id'));
        self::assertSame(403, $this->call($shared.'/cash-entries', $sharedReader)->getStatusCode());
        self::assertSame(403, $this->call($shared.'/commands/draft-from-cash', $sharedReader, $source)->getStatusCode());
        self::assertSame(404, $this->call($books[1].'/commands/draft-from-cash', $headToken, $source)->getStatusCode());
        self::assertSame(409, $this->call($shared.'/commands/draft-from-cash', $headToken, $source)->getStatusCode());
        $mapping = ['category' => 'transport', 'debit_code' => 'EQUITY', 'credit_code' => 'CASH'];
        self::assertSame([], $this->data($this->call($shared.'/cash-mappings', $headToken)));
        self::assertSame(403, $this->call($shared.'/cash-mappings', $sharedReader)->getStatusCode());
        self::assertSame(403, $this->call($shared.'/cash-mappings', $sharedReader, $mapping)->getStatusCode());
        self::assertSame(400, $this->call($shared.'/cash-mappings', $headToken, ['debit_code' => 'CASH'] + $mapping)->getStatusCode());
        self::assertSame(409, $this->call($shared.'/cash-mappings', $headToken, ['debit_code' => 'MISSING'] + $mapping)->getStatusCode());
        self::assertSame('transport', $this->data($this->call($shared.'/cash-mappings', $headToken, $mapping))['id']);
        self::assertSame(409, $this->call($shared.'/cash-mappings', $headToken, $mapping)->getStatusCode());
        $configured = $this->data($this->call($shared.'/cash-mappings', $headToken));
        self::assertCount(1, $configured);
        self::assertSame('cash_mapping_configured', $configured[0]['status']);
        self::assertSame('CASH - CASH', $configured[0]['credit_account']);
        self::assertSame('transport', $this->data($this->call($shared.'/cash-mappings/transport', $headToken))['id']);
        self::assertSame(404, $this->call($shared.'/cash-mappings/unknown', $headToken)->getStatusCode());
        self::assertSame([], $this->data($this->call($books[1].'/cash-mappings', $headToken)));
        self::assertSame('transport', $this->data($this->call($shared.'/cash-mappings/transport', $headToken, [
            'category' => 'transport', 'debit_code' => 'CASH', 'credit_code' => 'EQUITY',
        ], 'PUT'))['id']);
        self::assertSame(400, $this->call($shared.'/cash-mappings/transport', $headToken, [
            'category' => 'purchase', 'debit_code' => 'CASH', 'credit_code' => 'EQUITY',
        ], 'PUT')->getStatusCode());
        self::assertSame(['id' => 'transport'], $this->data($this->call($shared.'/cash-mappings/transport', $headToken, null, 'DELETE')));
        self::assertSame([], $this->data($this->call($shared.'/cash-mappings', $headToken)));
        self::assertSame(409, $this->call($shared.'/commands/draft-from-cash', $headToken, $source)->getStatusCode());
        self::assertSame('transport', $this->data($this->call($shared.'/cash-mappings', $headToken, $mapping))['id']);
        self::assertSame(404, $this->call($shared.'/commands/delete-cash-mapping', $headToken, ['category' => 'purchase'])->getStatusCode());
        $configured = $this->data($this->call($shared.'/cash-mappings?page=2&limit=1', $headToken));
        self::assertSame([], $configured);
        $generated = $this->data($this->call($shared.'/commands/draft-from-cash', $headToken, $source));
        self::assertFalse($generated['existing']);
        $retry = $this->data($this->call($shared.'/commands/draft-from-cash', $headToken, $source));
        self::assertTrue($retry['existing']);
        self::assertSame($generated['id'], $retry['id']);
        $fromCash = $this->data($this->call($shared.'/journals/'.$generated['id'], $headToken));
        self::assertSame('draft', $fromCash['state']);
        self::assertSame('2026-01-08', $fromCash['accounting_date']);
        self::assertSame($cash->getUuid(), $fromCash['cash_source_uuid']);
        self::assertSame($c->getUuid(), $fromCash['store_uuid']);
        self::assertSame(['12000', '12000'], array_column($fromCash['lines'], 'amount'));
        self::assertSame(['EQUITY', 'CASH'], array_column($fromCash['lines'], 'account_code'));
        self::assertSame(409, $this->call($shared.'/commands/update-draft', $headToken, [
            'id' => $generated['id'], 'revision' => $generated['revision'], 'summary' => 'Change store',
            'accounting_date' => '2026-01-08', 'store_uuid' => $a->getUuid(), 'lines' => $draft['lines'],
        ])->getStatusCode());
        self::assertSame($generated['id'], $this->data($this->call($shared.'/cash-entries', $headToken))[0]['journal_id']);
        self::assertSame(404, $this->call($own.'/journals/'.$generated['id'], $managerToken)->getStatusCode());
        $storeDetail = $this->data($this->call($sharedStore.'/journals/'.$generated['id'], $sharedReader));
        self::assertArrayNotHasKey('cash_source_uuid', $storeDetail);
        self::assertNotSame('Freight', $storeDetail['summary']);
        $this->data($this->call($shared.'/commands/void-draft', $headToken, [
            'id' => $generated['id'], 'revision' => $generated['revision'], 'reason' => 'Fixture void', 'command_id' => UUID::v4(),
        ]));
        self::assertSame($generated['id'], $this->data($this->call($shared.'/commands/draft-from-cash', $headToken, $source))['id']);

        $voidable = new StoreCashEntry($c->getUuid(), $sharedManager->getUuid(), new \DateTimeImmutable('2026-01-09'), 'CNY', 'expense', 'maintenance', 500, 'Duplicate', []);
        $linked = new StoreCashEntry($c->getUuid(), $sharedManager->getUuid(), new \DateTimeImmutable('2026-01-10'), 'CNY', 'expense', 'transport', 100, 'Linked', []);
        foreach ([$voidable, $linked] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $voidInput = ['id' => $voidable->getUuid(), 'reason' => 'Duplicate entry', 'command_id' => UUID::v4()];
        self::assertSame(403, $this->call($shared.'/commands/void-cash-entry', $sharedReader, $voidInput)->getStatusCode());
        self::assertSame(404, $this->call($books[1].'/commands/void-cash-entry', $headToken, $voidInput)->getStatusCode());
        self::assertSame(['id' => $voidable->getUuid()], $this->data($this->call($shared.'/commands/void-cash-entry', $headToken, $voidInput)));
        self::assertSame(['id' => $voidable->getUuid()], $this->data($this->call($shared.'/commands/void-cash-entry', $headToken, $voidInput)));
        self::assertSame(409, $this->call($shared.'/commands/void-cash-entry', $headToken, ['command_id' => UUID::v4()] + $voidInput)->getStatusCode());
        self::assertSame(409, $this->call($shared.'/commands/draft-from-cash', $headToken, ['source_uuid' => $voidable->getUuid()])->getStatusCode());
        $cashStates = array_column($this->data($this->call($shared.'/cash-entries', $headToken)), 'state', 'id');
        self::assertSame('voided', $cashStates[$voidable->getUuid()]);
        self::assertSame('active', $cashStates[$cash->getUuid()]);
        $monthEntries = array_column($this->data($this->call('/store/'.$c->getUuid().'/cash-register?month=2026-01', $sharedReader))['entries'], 'uuid');
        self::assertNotContains($voidable->getUuid(), $monthEntries);

        $linkedDraft = $this->data($this->call($shared.'/commands/draft-from-cash', $headToken, ['source_uuid' => $linked->getUuid()]));
        $linkedVoid = ['id' => $linked->getUuid(), 'reason' => 'Wrong source', 'command_id' => UUID::v4()];
        self::assertSame(409, $this->call($shared.'/commands/void-cash-entry', $headToken, $linkedVoid)->getStatusCode());
        $this->data($this->call($shared.'/commands/void-draft', $headToken, [
            'id' => $linkedDraft['id'], 'revision' => $linkedDraft['revision'], 'reason' => 'Discard draft', 'command_id' => UUID::v4(),
        ]));
        self::assertSame(['id' => $linked->getUuid()], $this->data($this->call($shared.'/commands/void-cash-entry', $headToken, $linkedVoid)));
        self::assertSame(409, $this->call($shared.'/commands/draft-from-cash', $headToken, ['source_uuid' => $linked->getUuid()])->getStatusCode());

        $recorderToken = $tokens->createAccessToken($recorder);
        $workspace = $this->data($this->call($shared.'/workspace', $recorderToken));
        self::assertSame(['read', 'cash_record'], $workspace['capabilities']);
        self::assertSame(['ledger-a', 'ledger-c'], array_column($workspace['cash_stores'], 'code'));
        $record = [
            'store_uuid' => $c->getUuid(), 'command_id' => UUID::v4(), 'date' => '2026-02-01',
            'category' => 'purchase', 'direction' => 'expense', 'amount' => '12000', 'note' => 'Beans',
            'metadata' => ['item' => 'Beans', 'quantity' => '2.5', 'unit' => 'kg'],
        ];
        self::assertSame(403, $this->call($shared.'/cash-entries', $sharedReader, $record)->getStatusCode());
        self::assertSame(409, $this->call($books[1].'/cash-entries', $recorderToken, $record)->getStatusCode());
        self::assertSame(400, $this->call($shared.'/cash-entries', $recorderToken, ['direction' => 'income'] + $record)->getStatusCode());
        self::assertSame(400, $this->call($shared.'/cash-entries', $recorderToken, ['metadata' => []] + $record)->getStatusCode());
        $saved = $this->data($this->call($shared.'/cash-entries', $recorderToken, $record));
        self::assertSame($saved, $this->data($this->call($shared.'/cash-entries', $recorderToken, $record)));
        self::assertSame(409, $this->call($shared.'/cash-entries', $recorderToken, ['amount' => '42'] + $record)->getStatusCode());
        self::assertSame(403, $this->call($shared.'/commands/draft-from-cash', $recorderToken, ['source_uuid' => $saved['id']])->getStatusCode());
        $inFinance = $this->data($this->call($shared.'/cash-entries?category=purchase', $recorderToken));
        self::assertSame([$saved['id']], array_column($inFinance, 'id'));
        self::assertNull($inFinance[0]['journal_id']);
        $inStore = $this->data($this->call('/store/'.$c->getUuid().'/cash-register?month=2026-02', $sharedReader));
        self::assertSame([$saved['id']], array_column($inStore['entries'], 'uuid'));
        self::assertSame('0.048', $inStore['entries'][0]['purchaseCost']['unitPrice']);
        self::assertSame($recorder->getUuid(), $em->find(StoreCashEntry::class, $saved['id'])->getActorUuid());
        $correction = [
            'id' => $saved['id'], 'cash_version' => $inFinance[0]['cash_version'], 'command_id' => UUID::v4(),
            'date' => '2026-02-02', 'category' => 'purchase', 'amount' => '10000', 'note' => 'Corrected',
            'item' => 'Beans', 'quantity' => '2.5', 'unit' => 'kg', 'reason' => 'Mistyped amount',
        ];
        self::assertSame(403, $this->call($shared.'/commands/correct-cash-entry', $sharedReader, $correction)->getStatusCode());
        self::assertSame(404, $this->call($books[1].'/commands/correct-cash-entry', $recorderToken, $correction)->getStatusCode());
        self::assertSame(409, $this->call($shared.'/commands/correct-cash-entry', $recorderToken, ['id' => $cash->getUuid()] + $correction)->getStatusCode());
        self::assertSame($saved, $this->data($this->call($shared.'/commands/correct-cash-entry', $recorderToken, $correction)));
        self::assertSame($saved, $this->data($this->call($shared.'/commands/correct-cash-entry', $recorderToken, $correction)));
        self::assertSame(409, $this->call($shared.'/commands/correct-cash-entry', $recorderToken, ['command_id' => UUID::v4()] + $correction)->getStatusCode());
        $updated = $this->data($this->call('/store/'.$c->getUuid().'/cash-register?month=2026-02', $sharedReader))['entries'][0];
        self::assertSame('10000', $updated['amount']);
        self::assertSame('2026-02-02', $updated['date']);
        $audit = $this->data($this->call($shared.'/audit', $headToken));
        $correctionAudit = array_values(array_filter($audit, static fn (array $row) => $row['action'] === 'correct-cash-entry'));
        self::assertCount(1, $correctionAudit);
        $auditDetail = $this->data($this->call($shared.'/audit/'.$correctionAudit[0]['id'], $headToken));
        self::assertSame('12000', $auditDetail['detail']['before']['amount']);
        self::assertSame('10000', $auditDetail['detail']['after']['amount']);

        $unbind = ['reason' => 'Incorrect assignment', 'command_id' => UUID::v4()];
        self::assertSame(409, $this->call($shared.'/commands/unbind-store', $headToken, ['id' => $c->getUuid()] + $unbind)->getStatusCode());
        $temporary = new Store('ledger-temp', 'Temporary binding');
        $em->persist($temporary);
        $em->flush();
        $temporaryUuid = $temporary->getUuid();
        $this->data($this->call($shared.'/commands/bind-store', $headToken, ['store_uuid' => $temporaryUuid]));
        $unbind += ['id' => $temporaryUuid];
        self::assertSame(403, $this->call($shared.'/commands/unbind-store', $recorderToken, $unbind)->getStatusCode());
        self::assertSame(['id' => $temporaryUuid], $this->data($this->call($shared.'/commands/unbind-store', $headToken, $unbind)));
        $this->data($this->call($books[1].'/commands/bind-store', $headToken, ['store_uuid' => $temporaryUuid]));
        self::assertSame(['id' => $temporaryUuid], $this->data($this->call($shared.'/commands/unbind-store', $headToken, $unbind)));
        self::assertContains($temporaryUuid, $this->data($this->call($books[1].'/workspace', $headToken))['store_uuids']);
    }

    private function call(string $path, string $token, ?array $body = null, string $method = ''): Response
    {
        return self::$kernel->handle(Request::create('/api/v1'.$path, $method !== '' ? $method : ($body === null ? 'GET' : 'POST'),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function data(Response $response): array
    {
        self::assertContains($response->getStatusCode(), [200, 201], $response->getContent());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
