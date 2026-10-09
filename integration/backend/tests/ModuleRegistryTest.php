<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use NsUltimate\Integration\Backend\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleRegistryTest extends TestCase
{
    public function testDiscoversModulesFromTheirSourceDirectories(): void
    {
        $businessRoot = sys_get_temp_dir().'/ns-ultimate-modules-'.bin2hex(random_bytes(8));
        mkdir($businessRoot.'/Dummy/src', 0777, true);
        mkdir($businessRoot.'/MemberCenter/src', 0777, true);
        mkdir($businessRoot.'/documentation');

        try {
            $modules = ModuleRegistry::discover($businessRoot);

            self::assertSame(['Dummy', 'MemberCenter'], array_column($modules, 'name'));
            self::assertSame('NsUltimate\\Business\\Dummy\\', $modules[0]['namespace']);
            self::assertSame('NsUltimate\\Business\\MemberCenter\\', $modules[1]['namespace']);
            self::assertSame('BusinessMemberCenter', $modules[1]['doctrine_alias']);
            self::assertSame('NsUltimate\\Business\\Dummy\\Migrations', $modules[0]['migration_namespace']);
        } finally {
            rmdir($businessRoot.'/Dummy/src');
            rmdir($businessRoot.'/Dummy');
            rmdir($businessRoot.'/MemberCenter/src');
            rmdir($businessRoot.'/MemberCenter');
            rmdir($businessRoot.'/documentation');
            rmdir($businessRoot);
        }
    }

    public function testRejectsModuleDirectoryNamesThatCannotMapToNamespaces(): void
    {
        $businessRoot = sys_get_temp_dir().'/ns-ultimate-invalid-module-'.bin2hex(random_bytes(8));
        mkdir($businessRoot.'/invalidName/src', 0777, true);

        try {
            $this->expectException(RuntimeException::class);
            ModuleRegistry::discover($businessRoot);
        } finally {
            rmdir($businessRoot.'/invalidName/src');
            rmdir($businessRoot.'/invalidName');
            rmdir($businessRoot);
        }
    }
}
