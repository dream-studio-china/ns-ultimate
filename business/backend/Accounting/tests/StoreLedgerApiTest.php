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
        $a = new Store('ledger-a', 'Ledger A');
        $b = new Store('ledger-b', 'Ledger B');
        $c = new Store('ledger-c', 'Ledger C');
        $manager = (new User())->setUsername('ledger-manager')->setEmail('ledger-manager@example.test');
        $sharedManager = (new User())->setUsername('shared-manager')->setEmail('shared-manager@example.test');
        $head = (new User())->setUsername('ledger-head')->setEmail('ledger-head@example.test')->setRoles(['ROLE_ADMIN']);
        foreach ([$a, $b, $c, $manager, $sharedManager, $head, new Membership($a, $manager->getUuid(), Membership::ROLE_MANAGER),
            new Membership($b, $manager->getUuid(), Membership::ROLE_MANAGER),
            new Membership($c, $sharedManager->getUuid(), Membership::ROLE_MANAGER),
            new Assignment($role, $sharedManager->getUuid(), Assignment::SCOPE_STORE, $c->getUuid()),
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
    }

    private function call(string $path, string $token, ?array $body = null): Response
    {
        return self::$kernel->handle(Request::create('/api/v1'.$path, $body === null ? 'GET' : 'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function data(Response $response): array
    {
        self::assertContains($response->getStatusCode(), [200, 201], $response->getContent());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
