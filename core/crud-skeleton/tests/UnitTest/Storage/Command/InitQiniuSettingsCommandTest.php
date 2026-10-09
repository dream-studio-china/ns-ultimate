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

#[AllowMockObjectsWithoutExpectations]
final class InitQiniuSettingsCommandTest extends TestCase
{
    public function testExecuteCreatesMissingQiniuSettingsWithProvidedValues(): void
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
            '--access-key' => ' access ',
            '--secret-key' => ' secret ',
            '--bucket' => ' bucket ',
            '--domain' => ' https://cdn.example.com ',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['qiniu.access_key', 'qiniu.secret_key', 'qiniu.bucket', 'qiniu.domain'], array_keys($persisted));
        self::assertSame('access', $persisted['qiniu.access_key']->getValue());
        self::assertSame('secret', $persisted['qiniu.secret_key']->getValue());
        self::assertSame('bucket', $persisted['qiniu.bucket']->getValue());
        self::assertSame('https://cdn.example.com', $persisted['qiniu.domain']->getValue());
        self::assertSame('storage', $persisted['qiniu.domain']->getGroupName());
        self::assertSame('Qiniu Domain', $persisted['qiniu.domain']->getLabel());
        self::assertStringContainsString('Created 4 Qiniu storage setting(s).', $tester->getDisplay());
    }

    public function testExecuteSkipsExistingSettingsAndCreatesMissingOnly(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(SettingRepository::class);
        $existing = new Setting('qiniu.access_key');
        $persisted = [];

        $repo->expects(self::exactly(4))
            ->method('findByKey')
            ->willReturnMap([
                ['qiniu.access_key', $existing],
                ['qiniu.secret_key', null],
                ['qiniu.bucket', null],
                ['qiniu.domain', null],
            ]);

        $em->expects(self::exactly(3))
            ->method('persist')
            ->with(self::callback(function (Setting $setting) use (&$persisted): bool {
                $persisted[] = $setting->getKey();

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new InitQiniuSettingsCommand($em, $repo));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['qiniu.secret_key', 'qiniu.bucket', 'qiniu.domain'], $persisted);
        self::assertStringContainsString('qiniu.access_key', $tester->getDisplay());
    }

    public function testExecuteDoesNotFlushWhenAllSettingsExist(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(SettingRepository::class);

        $repo->expects(self::exactly(4))
            ->method('findByKey')
            ->willReturn(new Setting('existing'));
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $tester = new CommandTester(new InitQiniuSettingsCommand($em, $repo));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('All Qiniu storage settings already exist.', $tester->getDisplay());
    }
}
