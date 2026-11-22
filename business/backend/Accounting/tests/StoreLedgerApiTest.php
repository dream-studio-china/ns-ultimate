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
        $manager = (new User())->setUsername('ledger-manager')->setEmail('ledger-manager@example.test');
        $head = (new User())->setUsername('ledger-head')->setEmail('ledger-head@example.test')->setRoles(['ROLE_ADMIN']);
        foreach ([$a, $b, $manager, $head, new Membership($a, $manager->getUuid(), Membership::ROLE_MANAGER),
            new Membership($b, $manager->getUuid(), Membership::ROLE_MANAGER),
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
        foreach ([$a, $b] as $i => $store) {
            $params = $initial + ['store_uuid' => $store->getUuid()];
            $book = $this->data($this->call($finance.'/books', $headToken, $params));
            self::assertSame($book['id'], $this->data($this->call($finance.'/books', $headToken, $params))['id']);
            $path = $finance.'/book/'.$book['id'];
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
