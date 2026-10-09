<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Storage\Command;

use App\Common\Entity\Setting;
use App\Common\Repository\SettingRepository;
use App\Storage\Command\InitQiniuSettingsCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Complements InitQiniuSettingsCommandTest by covering the empty/whitespace
 * option-value branch (value collapses to null instead of '').
 */
#[AllowMockObjectsWithoutExpectations]
final class InitQiniuSettingsCommandValuesTest extends TestCase
{
    public function testExecuteCreatesSettingsWithNullValuesForEmptyOptions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(SettingRepository::class);
        $persisted = [];

        $repo->expects(self::exactly(4))
            ->method('findByKey')
            ->willReturn(null);

        $em->expects(self::exactly(4))
            ->method('persist')
            ->with(self::callback(function (Setting $setting) use (&$persisted): bool {
                $persisted[$setting->getKey()] = $setting;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new InitQiniuSettingsCommand($em, $repo));
        $exitCode = $tester->execute([
            '--access-key' => '   ',
            '--secret-key' => '',
            '--bucket' => '   ',
            '--domain' => '',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            ['qiniu.access_key', 'qiniu.secret_key', 'qiniu.bucket', 'qiniu.domain'],
            array_keys($persisted)
        );
        self::assertNull($persisted['qiniu.access_key']->getValue());
        self::assertNull($persisted['qiniu.secret_key']->getValue());
        self::assertNull($persisted['qiniu.bucket']->getValue());
        self::assertNull($persisted['qiniu.domain']->getValue());
        self::assertSame('string', $persisted['qiniu.access_key']->getType());
        self::assertStringContainsString('Created 4 Qiniu storage setting(s).', $tester->getDisplay());
    }
}
