<?php

declare(strict_types=1);

namespace NsUltimate\Business\Dummy\Tests\Integration;

use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DummiesAuthorizationTest extends WebTestCase
{
    public function testDummiesManagementRequiresAdminAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/manage/dummies');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
