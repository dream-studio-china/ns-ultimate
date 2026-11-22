<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Tests;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Accounting\Migrations\Version20261121100000;
use NsUltimate\Business\Accounting\Migrations\Version20261122110000;
use NsUltimate\Business\Accounting\Migrations\Version20261122120000;
use NsUltimate\Business\Accounting\Migrations\Version20261122130000;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LedgerApiTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    public function testAuthorizationProtectedWorkflowAndCsv(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->assertMigrationMatchesOrm($em, $tool);
        $console = new CommandTester((new Application(self::$kernel))->find('app:accounting:seed-authorization'));
        self::assertSame(0, $console->execute([]));
        self::assertSame(0, $console->execute([]));
        self::assertSame(0, $em->getRepository(Assignment::class)->count([]));
        $finance = (new User())->setUsername('finance-writer')->setEmail('writer@example.test');
        $reader = (new User())->setUsername('finance-reader')->setEmail('reader@example.test');
        $outsider = (new User())->setUsername('finance-outsider')->setEmail('outsider@example.test');
        $admin = (new User())->setUsername('finance-admin')->setEmail('finance-admin@example.test')->setRoles(['ROLE_ADMIN']);
        foreach ([$finance, $reader, $outsider, $admin] as $user) {
            $em->persist($user);
        }
        $permissions = [];
        foreach (['read', 'audit', 'configure', 'draft', 'post', 'lock', 'reopen'] as $operation) {
            $permissions[$operation] = $em->getRepository(Permission::class)->findOneBy(['code' => 'accounting:ledger:'.$operation]);
        }
        $writerRole = new Role('finance_test_writer', 'Test writer', Role::SCOPE_GLOBAL);
        $readerRole = (new Role('finance_test_reader', 'Test reader', Role::SCOPE_GLOBAL))->addPermission($permissions['read']);
        $storeRole = (new Role('finance_test_store', 'Test store', Role::SCOPE_STORE))->addPermission($permissions['read']);
        foreach ($permissions as $permission) {
            $writerRole->addPermission($permission);
        }
        $grant = new Assignment($readerRole, $reader->getUuid(), Assignment::SCOPE_GLOBAL);
        foreach ([$writerRole, $readerRole, $storeRole, $grant,
            new Assignment($writerRole, $finance->getUuid(), Assignment::SCOPE_GLOBAL),
            new Assignment($storeRole, $outsider->getUuid(), Assignment::SCOPE_STORE, UUID::v4()),
        ] as $value) {
            $em->persist($value);
        }
        $em->flush();
        $tokens = self::getContainer()->get(TokenManager::class);
        $writerToken = $tokens->createAccessToken($finance);
        $readerToken = $tokens->createAccessToken($reader);
        $otherToken = $tokens->createAccessToken($outsider);
        $adminToken = $tokens->createAccessToken($admin);
        self::assertSame(401, $this->request('workspace')->getStatusCode());
        self::assertSame(401, $this->request('workspace', prefix: 'manage/accounting')->getStatusCode());
        self::assertSame(404, $this->request('workspace', $adminToken, prefix: 'finance')->getStatusCode());
        self::assertSame(403, $this->request('workspace', $otherToken)->getStatusCode());
        self::assertSame(403, $this->request('workspace', $otherToken, prefix: 'manage/accounting')->getStatusCode());
        self::assertSame(403, $this->request('workspace', $writerToken, prefix: 'manage/accounting')->getStatusCode());
        self::assertNull($this->ok('workspace', $adminToken, prefix: 'manage/accounting')['book']);
        self::assertEqualsCanonicalizing(['read', 'audit', 'draft', 'post', 'reverse', 'configure', 'lock', 'reopen'], $this->ok('workspace', $adminToken)['capabilities']);
        self::assertSame(403, $this->request('reports/trial/csv', $otherToken)->getStatusCode());
        self::assertSame(403, $this->request('commands/initialize', $readerToken, [])->getStatusCode());
        self::assertSame(403, $this->request('commands/reopen-period', $readerToken, [])->getStatusCode());
        self::assertSame(403, $this->request('audit', $readerToken)->getStatusCode());

        self::assertSame(['data' => [], 'code' => 0, 'message' => 'SUCCESS'], array_diff_key($this->body('books', $adminToken), ['paginator' => true]));
        self::assertSame(403, $this->request('books', $writerToken, [], $manage = 'manage/accounting')->getStatusCode());
        $bookSetup = ['entity' => 'Test', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'UTC', 'opening_date' => '2026-01-01'];
        $book = $this->request('books', $adminToken, $bookSetup, $manage);
        self::assertSame(201, $book->getStatusCode(), $book->getContent());
        self::assertEquals(['id' => 1, 'opening_state' => 'pending', 'store_uuid' => null] + $bookSetup, json_decode($book->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']);
        // The original command stays compatible: an identical initialization is a no-op.
        $this->ok('commands/initialize', $adminToken, $bookSetup, $manage);
        $books = $this->body('books?page=1&limit=20', $readerToken);
        self::assertSame([1], array_column($books['data'], 'id'));
        self::assertSame(['total' => 1, 'page' => 1, 'limit' => 20, 'pages' => 1, 'has_previous' => false, 'has_next' => false], $books['paginator']);
        self::assertSame('CNY', $this->ok('books/1', $readerToken)['currency']);
        self::assertSame(404, $this->request('books/2', $readerToken)->getStatusCode());
        self::assertSame(405, $this->request('books/1', $adminToken, $bookSetup, $manage, 'PUT')->getStatusCode());
        $this->ok('commands/add-period', $writerToken, ['month' => '2026-01']);
        foreach (['CASH' => 'asset', 'EQUITY' => 'equity'] as $code => $class) {
            $this->ok('commands/save-account', $writerToken, [
                'code' => $code, 'name' => $code, 'classification' => $class, 'normal_side' => 'debit', 'active' => true, 'postable' => true,
            ]);
        }
        $manage = 'manage/accounting';
        $account = ['code' => 'A.10', 'name' => 'Other cash', 'classification' => 'asset', 'normal_side' => 'debit', 'active' => true, 'postable' => false];
        self::assertSame(403, $this->request('ledger-accounts', $writerToken, $account, $manage)->getStatusCode());
        self::assertSame(403, $this->request('ledger-accounts', $readerToken, $account)->getStatusCode());
        $created = $this->request('ledger-accounts?_locale=en', $adminToken, $account, $manage);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        self::assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
        self::assertSame(409, $this->request('ledger-accounts', $adminToken, ['name' => 'Duplicate'] + $account, $manage)->getStatusCode());
        self::assertEquals($account + ['id' => 'A.10', 'book_id' => 1], $this->ok('ledger-accounts/A.10', $readerToken));
        $update = ['name' => 'Renamed'] + array_diff_key($account, ['code' => true]);
        self::assertSame(400, $this->request('ledger-accounts/A.10', $adminToken, $update + ['code' => 'OTHER'], $manage, 'PUT')->getStatusCode());
        self::assertSame(404, $this->request('ledger-accounts/MISSING', $adminToken, $update, $manage, 'PUT')->getStatusCode());
        self::assertSame(200, $this->request('ledger-accounts/A.10', $writerToken, $update, method: 'PUT')->getStatusCode());
        self::assertSame('Renamed', $this->ok('ledger-accounts/A.10', $adminToken, prefix: $manage)['name']);
        self::assertSame(['A.10'], array_column($this->ok('ledger-accounts?q=rename&page=1&limit=20', $adminToken, prefix: $manage), 'id'));
        self::assertSame(['EQUITY'], array_column($this->ok('ledger-accounts?%40order=entity.code%7CDESC&limit=1', $adminToken, prefix: $manage), 'id'));
        $page = $this->request('ledger-accounts?page=2&limit=1', $adminToken, prefix: $manage);
        $list = json_decode($page->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['CASH'], array_column($list['data'], 'id'));
        self::assertSame(3, $list['paginator']['total']);
        self::assertSame(2, $list['paginator']['page']);
        self::assertSame(400, $this->request('ledger-accounts?%40filter=anything', $adminToken, prefix: $manage)->getStatusCode());
        self::assertSame(405, $this->request('ledger-accounts/A.10', $adminToken, prefix: $manage, method: 'DELETE')->getStatusCode());
        self::assertSame(403, $this->request('accounting-periods', $readerToken, ['month' => '2026-02'])->getStatusCode());
        self::assertSame(400, $this->request('accounting-periods', $adminToken, ['month' => '2026-02', 'state' => 'locked'], $manage)->getStatusCode());
        self::assertSame(201, $this->request('accounting-periods', $adminToken, ['month' => '2026-02'], $manage)->getStatusCode());
        self::assertSame(409, $this->request('accounting-periods', $adminToken, ['month' => '2026-02'], $manage)->getStatusCode());
        self::assertSame(['2026-02', '2026-01'], array_column($this->ok('accounting-periods?page=1&limit=20', $adminToken, prefix: $manage), 'id'));
        self::assertSame('open', $this->ok('accounting-periods/2026-02', $readerToken)['state']);
        self::assertSame(405, $this->request('accounting-periods/2026-02', $adminToken, ['state' => 'locked'], $manage, 'PUT')->getStatusCode());
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM ns_accounting_audit WHERE action IN ('create-account', 'update-account')"));
        $this->ok('commands/approve-zero-opening', $writerToken, ['reason' => 'No opening position', 'command_id' => UUID::v4()]);
        $draftPayload = [
            'origin' => 'manual', 'accounting_date' => '2026-01-02', 'summary' => '=1+1', 'evidence' => 'restricted-test-evidence',
            'lines' => [['account_code' => 'CASH', 'side' => 'debit', 'amount' => '9007199254740993'],
                        ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => '9007199254740993']],
            'command_id' => UUID::v4(),
        ];
        $extra = ['source_id' => 'restricted-fixture-id', 'context' => (object) ['items' => [new \stdClass(), []]]];
        foreach ([null, [], ['not-an-object'], 'text', ['too_large' => str_repeat('a', 8192)]] as $invalid) {
            self::assertSame(400, $this->request('commands/create-draft', $writerToken, $draftPayload + ['metadata' => $invalid])->getStatusCode());
        }
        self::assertSame(400, $this->request('commands/create-draft', $writerToken, $draftPayload + ['state' => 'posted'])->getStatusCode());
        $badLine = $draftPayload;
        $badLine['lines'][0]['credit'] = '1';
        self::assertSame(400, $this->request('commands/create-draft', $writerToken, $badLine)->getStatusCode());
        self::assertSame(403, $this->request('commands/create-draft', $writerToken, $draftPayload, 'manage/accounting')->getStatusCode());
        $draft = $this->ok('commands/create-draft', $adminToken, $draftPayload, 'manage/accounting');
        self::assertSame($draft, $this->ok('commands/create-draft', $adminToken, $draftPayload));
        self::assertSame($draft, $this->ok('commands/create-draft', $adminToken, $draftPayload + ['metadata' => new \stdClass(), 'attachments' => []]));
        $listing = $this->body('journals?account=CASH&from=2026-01-01&to=2026-01-31&state=draft', $readerToken);
        self::assertSame([$draft['id']], array_column($listing['data'], 'id'));
        self::assertSame(1, $listing['data'][0]['revision']);
        self::assertSame(['total' => 1, 'page' => 1, 'limit' => 20, 'pages' => 1, 'has_previous' => false, 'has_next' => false], $listing['paginator']);
        $secondPage = $this->body('journals?page=2&limit=1', $readerToken);
        self::assertSame([], $secondPage['data']);
        self::assertSame(2, $secondPage['paginator']['page']);
        self::assertSame(400, $this->request('journals?limit=301', $readerToken)->getStatusCode());
        self::assertSame(400, $this->request('journals?%40display=reduce', $adminToken, prefix: 'manage/accounting')->getStatusCode());
        self::assertSame(400, $this->request('journals?from=invalid', $readerToken)->getStatusCode());
        self::assertSame(405, $this->request('journals/'.$draft['id'], $adminToken, ['state' => 'posted'], 'manage/accounting')->getStatusCode());
        self::assertSame(405, $this->request('journals/'.$draft['id'], $adminToken, prefix: 'manage/accounting', method: 'DELETE')->getStatusCode());
        self::assertArrayNotHasKey('evidence', $this->ok('journals/'.$draft['id'], $readerToken));
        self::assertArrayNotHasKey('attachments', $this->ok('journals/'.$draft['id'], $readerToken));
        self::assertArrayNotHasKey('metadata', $this->ok('journals/'.$draft['id'], $readerToken));
        $detail = 'journals/'.$draft['id'];
        self::assertStringContainsString('"metadata":{}', $this->request($detail, $writerToken)->getContent());
        self::assertSame('restricted-test-evidence', $this->ok($detail, $writerToken)['evidence']);
        $update = array_intersect_key($draftPayload, array_flip(['accounting_date', 'summary', 'lines']));
        $draft = $this->ok('commands/update-draft', $writerToken, $draft + $update + ['metadata' => $extra]);
        $draft = $this->ok('commands/update-draft', $writerToken, $draft + $update);
        $view = $this->request($detail, $writerToken);
        self::assertStringContainsString('"items":[{},[]]', $view->getContent());
        self::assertStringContainsString('restricted-fixture-id', $view->getContent());
        $draft = $this->ok('commands/update-draft', $writerToken, $draft + $update + ['metadata' => new \stdClass()]);
        self::assertStringContainsString('"metadata":{}', $this->request($detail, $writerToken)->getContent());
        $draft = $this->ok('commands/update-draft', $writerToken, $draft + $update + ['metadata' => $extra]);
        $this->ok('commands/post', $writerToken, $draft + ['command_id' => UUID::v4()]);
        self::assertStringContainsString('restricted-fixture-id', $this->request($detail, $writerToken)->getContent());
        self::assertSame(409, $this->request('commands/update-draft', $writerToken, ['revision' => $draft['revision'] + 1] + $draft + $update + ['metadata' => new \stdClass()])->getStatusCode());

        // Standard resource writes only create and revise drafts, through the same ledger commands.
        $lines = [['account_code' => 'CASH', 'side' => 'debit', 'amount' => '500', 'description' => ''],
                  ['account_code' => 'EQUITY', 'side' => 'credit', 'amount' => '500', 'description' => 'Form line']];
        $form = ['origin' => 'manual', 'accounting_date' => '2026-01-03', 'summary' => 'Form draft', 'metadata' => new \stdClass(), 'lines' => $lines];
        self::assertSame(403, $this->request('journals', $readerToken, $form)->getStatusCode());
        self::assertSame(400, $this->request('journals', $writerToken, $form + ['state' => 'posted'])->getStatusCode());
        $created = $this->request('journals', $writerToken, $form);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $formDraft = json_decode($created->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['draft', 1, 'Form draft', 2], [$formDraft['state'], $formDraft['revision'], $formDraft['summary'], count($formDraft['lines'])]);
        $formPath = 'journals/'.$formDraft['id'];
        $keyed = ['command_id' => UUID::v4()] + $form;
        $replayed = $this->request('journals', $writerToken, $keyed);
        self::assertSame(201, $replayed->getStatusCode(), $replayed->getContent());
        self::assertSame(json_decode($replayed->getContent(), true)['data']['id'], json_decode($this->request('journals', $writerToken, $keyed)->getContent(), true)['data']['id']);
        $revision = ['revision' => 1, 'accounting_date' => '2026-01-03', 'summary' => 'Revised form draft', 'lines' => $lines];
        self::assertSame(400, $this->request($formPath, $writerToken, $revision + ['id' => $draft['id']], method: 'PUT')->getStatusCode());
        self::assertSame(400, $this->request($formPath, $writerToken, $revision + ['origin' => 'opening'], method: 'PUT')->getStatusCode());
        $revised = $this->request($formPath, $writerToken, $revision, method: 'PUT');
        self::assertSame(200, $revised->getStatusCode(), $revised->getContent());
        self::assertStringContainsString('"metadata":{}', $revised->getContent());
        self::assertSame([2, 'Revised form draft'], array_values(array_intersect_key(json_decode($revised->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'], ['revision' => 0, 'summary' => 0])));
        self::assertSame(409, $this->request($formPath, $writerToken, $revision, method: 'PUT')->getStatusCode());
        self::assertSame(409, $this->request($detail, $writerToken, ['revision' => $draft['revision']] + $revision, method: 'PUT')->getStatusCode());
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM ns_accounting_audit WHERE target = ? AND action IN ('create-draft', 'update-draft')", [$formDraft['id']]));

        $audit = $this->body('audit?limit=2', $writerToken);
        self::assertCount(2, $audit['data']);
        self::assertSame(2, $audit['paginator']['limit']);
        self::assertGreaterThan(2, $audit['paginator']['total']);
        self::assertSame($audit['data'][0], $this->ok('audit/'.$audit['data'][0]['id'], $writerToken));
        self::assertSame(403, $this->request('audit/'.$audit['data'][0]['id'], $readerToken)->getStatusCode());
        self::assertSame(404, $this->request('audit/'.UUID::v4(), $writerToken)->getStatusCode());

        $trialQuery = 'reports/trial?from=2026-01-01&to=2026-01-31&limit=1';
        $trial = $this->body($trialQuery, $readerToken);
        self::assertSame(['CASH'], array_column($trial['data'], 'code'));
        self::assertSame(2, $trial['paginator']['total']);
        self::assertSame(['kind' => 'trial', 'cutoff' => '1', 'currency' => 'CNY', 'scale' => 2], array_diff_key($trial['report'], ['totals' => true]));
        self::assertSame('9007199254740993', $trial['report']['totals']['closing_debit']);
        $trialPage = $this->body($trialQuery.'&page=2&cutoff='.$trial['report']['cutoff'], $readerToken);
        self::assertSame(['EQUITY'], array_column($trialPage['data'], 'code'));
        self::assertSame(400, $this->request($trialQuery.'&cutoff=2', $readerToken)->getStatusCode());
        $ledger = $this->body('reports/ledger?from=2026-01-01&to=2026-01-31&account=CASH', $readerToken);
        self::assertSame(['9007199254740993'], array_column($ledger['data'], 'balance'));
        self::assertSame(['0', 1], [$ledger['report']['opening'], $ledger['paginator']['total']]);
        $uri = 'reports/ledger/csv?from=2026-01-01&to=2026-01-31&account=CASH';
        $csv = $this->request($uri, $readerToken);
        self::assertSame(200, $csv->getStatusCode(), $csv->getContent());
        self::assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
        self::assertStringContainsString("'=1+1", $csv->getContent());
        self::assertStringContainsString('9007199254740993', $csv->getContent());
        $db->update('authorization_assignment', ['revoked_at' => '2026-01-03 00:00:00'], ['uuid' => $grant->getUuid()]);
        self::assertSame(403, $this->request($uri, $readerToken)->getStatusCode());
        $paths = glob(substr(getenv('AUDIT_LOG_PATH'), 0, -4).'-*.log') ?: [];
        self::assertNotEmpty($paths);
        $transportAudit = implode('', array_map('file_get_contents', $paths));
        self::assertStringContainsString('business_accounting_finance_command', $transportAudit);
        self::assertStringContainsString('business_accounting_manage_command', $transportAudit);
        self::assertStringNotContainsString('restricted-test-evidence', $transportAudit);
        self::assertStringNotContainsString('restricted-fixture-id', $transportAudit);
    }

    private function assertMigrationMatchesOrm(EntityManagerInterface $em, SchemaTool $tool): void
    {
        $metadata = array_values(array_filter($em->getMetadataFactory()->getAllMetadata(), static fn ($class) => str_starts_with($class->getTableName(), 'ns_accounting_')));
        $expected = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once __DIR__.'/AccountingTestManager.php';
        AccountingTestManager::migrate($expected, Version20261121100000::class, Version20261122110000::class, Version20261122120000::class, Version20261122130000::class);
        $from = $expected->createSchemaManager()->introspectSchema();
        $actual = new Schema(array_values(array_filter(
            $em->getConnection()->createSchemaManager()->introspectSchema()->getTables(),
            static fn ($table) => str_starts_with($table->getName(), 'ns_accounting_'),
        )));
        self::assertCount(8, $metadata);
        self::assertCount(8, array_filter($tool->getSchemaFromMetadata($metadata)->getTables(), static fn ($table) => str_starts_with($table->getName(), 'ns_accounting_')));
        $diff = $expected->createSchemaManager()->createComparator()->compareSchemas($from, $actual);
        self::assertSame([], $expected->getDatabasePlatform()->getAlterSchemaSQL($diff));
    }

    private function ok(string $path, string $token, ?array $body = null, string $prefix = 'finance/accounting'): array
    {
        return $this->body($path, $token, $body, $prefix)['data'];
    }

    private function body(string $path, string $token, ?array $body = null, string $prefix = 'finance/accounting'): array
    {
        $response = $this->request($path, $token, $body, $prefix);
        self::assertSame(200, $response->getStatusCode(), substr($response->getContent(), 0, 2000));

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(string $path, ?string $token = null, ?array $body = null, string $prefix = 'finance/accounting', ?string $method = null): Response
    {
        return self::$kernel->handle(Request::create(
            '/api/v1/'.$prefix.'/'.$path, $method ?? ($body === null ? 'GET' : 'POST'),
            server: ['CONTENT_TYPE' => 'application/json', ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token])],
            content: $body === null ? null : json_encode((object) $body, JSON_THROW_ON_ERROR),
        ));
    }
}
