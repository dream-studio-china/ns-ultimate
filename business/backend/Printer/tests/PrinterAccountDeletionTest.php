<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrinterAccountDeletionTest extends KernelTestCase
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

    public function testDeletesAnUnboundAccountAndRejectsAnAccountWithDevices(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('printer-account@example.test')->setUsername('printer-account-admin')->setRoles(['ROLE_ADMIN']);
        $empty = new PrinterAccount('Unbound', 'unbound', '');
        $bound = new PrinterAccount('Bound', 'bound', '');
        $device = new PrinterDevice($bound, 'Receipt', 'ACCOUNT-DELETE-TEST', PrinterDevice::KIND_RECEIPT);
        foreach ([$admin, $empty, $bound, $device] as $entity) { $em->persist($entity); }
        $em->flush();
        $token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);

        $blocked = $this->request('DELETE', '/api/v1/manage/printer/accounts/'.$bound->getId(), $token);
        self::assertSame(409, $blocked->getStatusCode(), $blocked->getContent());
        self::assertNotNull($em->getRepository(PrinterAccount::class)->findOneBy(['uuid' => $bound->getUuid()]));

        $deleted = $this->request('DELETE', '/api/v1/manage/printer/accounts/'.$empty->getId(), $token);
        self::assertSame(204, $deleted->getStatusCode(), $deleted->getContent());
        $em->clear();
        self::assertNull($em->getRepository(PrinterAccount::class)->findOneBy(['uuid' => $empty->getUuid()]));
        self::assertNotNull($em->getRepository(PrinterAccount::class)->findOneBy(['uuid' => $bound->getUuid()]));
    }

    private function request(string $method, string $path, string $token): Response
    {
        return self::$kernel->handle(Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}'));
    }
}
