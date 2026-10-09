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
        mkdir($businessRoot.'/Note/src', 0777, true);
        mkdir($businessRoot.'/MemberCenter/src', 0777, true);
        mkdir($businessRoot.'/documentation');

        try {
            $modules = ModuleRegistry::discover($businessRoot);

            self::assertSame(['MemberCenter', 'Note'], array_column($modules, 'name'));
            self::assertSame('NsUltimate\\Business\\MemberCenter\\', $modules[0]['namespace']);
            self::assertSame('BusinessMemberCenter', $modules[0]['doctrine_alias']);
            self::assertSame('NsUltimate\\Business\\Note\\Migrations', $modules[1]['migration_namespace']);
        } finally {
            rmdir($businessRoot.'/Note/src');
            rmdir($businessRoot.'/Note');
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
