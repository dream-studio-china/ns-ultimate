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
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\NullLogger;

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

        $this->ok('commands/initialize', $adminToken, ['entity' => 'Test', 'currency' => 'CNY', 'scale' => 2, 'timezone' => 'UTC', 'opening_date' => '2026-01-01'], 'manage/accounting');
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
        self::assertSame(400, $this->request('commands/create-draft', $writerToken, $draftPayload + ['state' => 'posted'])->getStatusCode());
        $badLine = $draftPayload;
        $badLine['lines'][0]['credit'] = '1';
        self::assertSame(400, $this->request('commands/create-draft', $writerToken, $badLine)->getStatusCode());
        self::assertSame(403, $this->request('commands/create-draft', $writerToken, $draftPayload, 'manage/accounting')->getStatusCode());
        $draft = $this->ok('commands/create-draft', $adminToken, $draftPayload, 'manage/accounting');
        self::assertSame($draft, $this->ok('commands/create-draft', $adminToken, $draftPayload));
        $listing = $this->ok('journals?account=CASH&from=2026-01-01&to=2026-01-31&state=draft', $readerToken);
        self::assertSame([$draft['id']], array_column($listing['rows'], 'id'));
        $secondPage = $this->ok('journals?page=2', $readerToken);
        self::assertSame([], $secondPage['rows']);
        self::assertSame(2, $secondPage['page']);
        self::assertSame(400, $this->request('journals?%40display=reduce', $adminToken, prefix: 'manage/accounting')->getStatusCode());
        self::assertSame(400, $this->request('journals?from=invalid', $readerToken)->getStatusCode());
        self::assertSame(405, $this->request('journals/'.$draft['id'], $adminToken, ['state' => 'posted'], 'manage/accounting')->getStatusCode());
        self::assertArrayNotHasKey('evidence', $this->ok('journals/'.$draft['id'], $readerToken));
        self::assertSame('restricted-test-evidence', $this->ok('journals/'.$draft['id'], $writerToken)['evidence']);
        $this->ok('commands/post', $writerToken, $draft + ['command_id' => UUID::v4()]);
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
    }

    private function assertMigrationMatchesOrm(EntityManagerInterface $em, SchemaTool $tool): void
    {
        $metadata = array_values(array_filter($em->getMetadataFactory()->getAllMetadata(), static fn ($class) => str_starts_with($class->getTableName(), 'ns_accounting_')));
        $expected = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__).'/migrations/Version20261121100000.php';
        $migration = new Version20261121100000($expected, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $expected->executeStatement($sql->getStatement());
        }
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
        $response = $this->request($path, $token, $body, $prefix);
        self::assertSame(200, $response->getStatusCode(), substr($response->getContent(), 0, 2000));

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
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
