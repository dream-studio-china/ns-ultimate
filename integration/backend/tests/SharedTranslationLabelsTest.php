<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SharedTranslationLabelsTest extends TestCase
{
    public function testSharedLabelsDoNotAcquireModuleSpecificMeanings(): void
    {
        // Business modules share the messages domain. Check every occurrence,
        // not just the winning catalog, so import order cannot hide a regression.
        $labels = [
            'Status' => ['Status', '状态', '狀態', '状態'],
            'Account' => ['Account', '账号', '帳號', 'アカウント'],
            'Provider' => ['Provider', '服务商', '服務商', 'プロバイダー'],
            'Kind' => ['Kind', '种类', '種類', '種類'],
            'Model' => ['Model', '型号', '型號', 'モデル'],
            'Definition' => ['Definition', '定义', '定義', '定義'],
            'Device' => ['Device', '设备', '裝置', '端末'],
            'Copies' => ['Copies', '份数', '份數', '部数'],
            'Configuration' => ['Configuration', '配置', '設定', '設定'],
            'Trigger' => ['Trigger', '触发器', '觸發器', 'トリガー'],
            'Events' => ['Events', '事件', '事件', 'イベント'],
            'Notes' => ['Notes', '备注', '備註', 'メモ'],
            'Session' => ['Session', '会话', '工作階段', 'セッション'],
            'Capacity' => ['Capacity', '容量', '容量', '容量'],
            'Participant count' => ['Participant count', '参与人数', '參與人數', '参加者数'],
            'Channel' => ['Channel', '渠道', '渠道', 'チャネル'],
            'Capabilities' => ['Capabilities', '能力', '能力', '機能'],
            'Attempt' => ['Attempt', '尝试', '嘗試', '試行'],
            'Confirmed amount' => ['Confirmed amount', '已确认金额', '已確認金額', '確認済み金額'],
            'Provider amount' => ['Provider amount', '服务商金额', '服務商金額', 'プロバイダーの金額'],
            'Core applied' => ['Applied to Core', '已应用到核心系统', '已套用至核心系統', 'Core へ適用済み'],
            'Fingerprint' => ['Fingerprint', '指纹', '指紋', '指紋'],
            'Official' => ['Official', '官方', '官方', '公式'],
        ];
        $root = dirname(__DIR__, 3);
        $catalogs = glob($root.'/business/backend/*/translations/messages.*.yaml') ?: [];
        $catalogs = array_merge($catalogs, glob($root.'/integration/backend/translations/messages.*.yaml') ?: []);
        self::assertNotEmpty($catalogs);
        $seen = [];
        foreach (['en', 'zh', 'zh_Hant', 'ja'] as $index => $locale) {
            foreach ($catalogs as $file) {
                if (!str_ends_with($file, '/messages.'.$locale.'.yaml')) {
                    continue;
                }
                $messages = Yaml::parseFile($file);
                self::assertIsArray($messages);
                foreach ($messages as $key => $value) {
                    foreach ($labels as $label => $translations) {
                        if (strcasecmp((string) $key, $label) !== 0) {
                            continue;
                        }
                        self::assertSame($translations[$index], $value, $file.': '.$key);
                        $seen[$locale][$label] = true;
                    }
                }
            }
            self::assertCount(count($labels), $seen[$locale] ?? [], $locale.' is missing a shared label');
        }
    }

    public function testExplicitPrinterEntityLabelsRetainTheirContext(): void
    {
        $directory = dirname(__DIR__, 3).'/business/backend/Printer/translations/';
        foreach (['en', 'zh', 'zh_Hant', 'ja'] as $locale) {
            $messages = Yaml::parseFile($directory.'messages.'.$locale.'.yaml');
            self::assertIsArray($messages);
            self::assertNotSame($messages['Account'], $messages['PrinterAccount']);
            self::assertNotSame($messages['Device'], $messages['PrinterDevice']);
        }
    }
}
