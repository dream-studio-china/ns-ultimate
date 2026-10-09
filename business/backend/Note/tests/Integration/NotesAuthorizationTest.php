<?php

declare(strict_types=1);

namespace NsUltimate\Business\Note\Tests\Integration;

use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotesAuthorizationTest extends WebTestCase
{
    public function testNotesManagementRequiresAdminAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/manage/notes');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
