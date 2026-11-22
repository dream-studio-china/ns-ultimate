<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PrinterJobAdminFilterTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($em->getConnection()->createSchemaManager()->listTableNames() !== []) { $tool->dropSchema($metadata); }
        $tool->createSchema($metadata);
    }

    public function testAdminJobListFiltersByStoreRuleAndDeviceSerialNumber(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('printer-job-filter@example.test')->setUsername('printer-job-filter')->setRoles(['ROLE_ADMIN']);
        $storeA = new Store('printer-job-a', 'Printer Job A');
        $storeB = new Store('printer-job-b', 'Printer Job B');
        $account = new PrinterAccount('Filter test', 'developer', '');
        $deviceA = new PrinterDevice($account, 'Printer A', 'SERIAL-A-100', PrinterDevice::KIND_RECEIPT, $storeA->getUuid());
        $deviceB = new PrinterDevice($account, 'Printer B', 'SERIAL-B-200', PrinterDevice::KIND_RECEIPT, $storeB->getUuid());
        foreach ([$admin, $storeA, $storeB, $account, $deviceA, $deviceB] as $entity) { $em->persist($entity); }
        $em->flush();

        $ruleA = UUID::v4();
        $ruleB = UUID::v4();
        $snapshot = ['kind' => 'receipt', 'nodes' => []];
        $jobA = new PrinterJob($deviceA, 1, $snapshot, [], 'A', 'filter-job-a', $ruleA);
        $jobB = new PrinterJob($deviceB, 1, $snapshot, [], 'B', 'filter-job-b', $ruleB);
        $manual = new PrinterJob($deviceA, 1, $snapshot, [], 'M', 'filter-job-manual');
        foreach ([$jobA, $jobB, $manual] as $job) { $em->persist($job); }
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);

        $uuids = fn (string $filter): array => $this->jobUuids($token, $filter);
        self::assertEqualsCanonicalizing([$jobA->getUuid(), $manual->getUuid()], $uuids("entity.getStoreUuid() == '".$storeA->getUuid()."'"));
        self::assertSame([$jobA->getUuid()], $uuids("entity.getRuleUuid() == '".$ruleA."'"));
        self::assertEqualsCanonicalizing([$jobA->getUuid(), $manual->getUuid()], $uuids("entity.getDevice().getSerialNumber() matches 'SERIAL-A'"));
        self::assertSame([], $uuids("entity.getStoreUuid() == '".$storeA->getUuid()."' && entity.getRuleUuid() == '".$ruleB."'"));
    }

    /** @return list<string> */
    private function jobUuids(string $token, string $filter): array
    {
        $request = Request::create('/api/v1/manage/printer/jobs?page=1&limit=20', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $request->query->set('@filter', $filter);
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(count($payload['data']), $payload['paginator']['total']);
        return array_column($payload['data'], 'uuid');
    }
}
