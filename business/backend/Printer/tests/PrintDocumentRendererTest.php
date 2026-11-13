<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use PHPUnit\Framework\TestCase;

final class PrintDocumentRendererTest extends TestCase
{
    public function testReceiptExpandsItemLoop(): void
    {
        $document = (new PrintDocumentRenderer())->render([
            'kind' => 'receipt',
            'nodes' => [
                ['type' => 'each', 'path' => 'order.items', 'children' => [
                    ['type' => 'field', 'path' => 'item.name'], ['type' => 'line'],
                ]],
            ],
        ], ['order' => ['items' => [['name' => 'Tea'], ['name' => 'Cake']]]]);

        self::assertSame(['Tea<BR>Cake<BR>'], $document['pages']);
        self::assertSame('Tea<BR>Cake<BR>', $document['content']);
    }

    public function testLabelLoopCreatesIndividualPagesAndEscapesText(): void
    {
        $document = (new PrintDocumentRenderer())->render([
            'kind' => 'label',
            'widthMm' => 50,
            'heightMm' => 40,
            'nodes' => [['type' => 'each', 'path' => 'items', 'children' => [
                ['type' => 'labelText', 'x' => 8, 'y' => 16, 'path' => 'item.name'],
            ]]],
        ], ['items' => [['name' => '<Tea>'], ['name' => 'Cake']]]);

        self::assertSame(['<TEXT x="8" y="16" font="9">&ltTea&gt</TEXT>', '<TEXT x="8" y="16" font="9">Cake</TEXT>'], $document['pages']);
        self::assertStringContainsString('<PAGE><SIZE>50,40</SIZE><TEXT x="8" y="16" font="9">&ltTea&gt</TEXT></PAGE>', $document['content']);
        self::assertCount(2, $document['pages']);
    }

    public function testRejectsUnboundedLoopData(): void
    {
        $items = array_fill(0, 101, ['name' => 'x']);
        $this->expectException(\InvalidArgumentException::class);
        (new PrintDocumentRenderer())->render([
            'kind' => 'receipt',
            'nodes' => [['type' => 'each', 'path' => 'items', 'children' => [['type' => 'field', 'path' => 'item.name']]]],
        ], ['items' => $items]);
    }

    public function testRejectsArbitraryTemplateNodeTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [['type' => 'php', 'value' => 'echo']]], []);
    }

    public function testRepeatExpandsLabelCountWithinEachItem(): void
    {
        $document = (new PrintDocumentRenderer())->render([
            'kind' => 'label', 'widthMm' => 40, 'heightMm' => 30,
            'nodes' => [['type' => 'each', 'path' => 'items', 'children' => [
                ['type' => 'repeat', 'countPath' => 'item.quantity', 'children' => [
                    ['type' => 'labelText', 'x' => 8, 'y' => 8, 'path' => 'item.name'],
                ]],
            ]]],
        ], ['items' => [['name' => 'Tea', 'quantity' => 2], ['name' => 'Cake', 'quantity' => 1]]]);

        self::assertCount(3, $document['pages']);
        self::assertStringContainsString('Tea', $document['pages'][0]);
        self::assertStringContainsString('Cake', $document['pages'][2]);
    }

    public function testIfNodeIncludesOptionalContentOnlyWhenConditionMatches(): void
    {
        $document = (new PrintDocumentRenderer())->render([
            'kind' => 'receipt',
            'nodes' => [
                ['type' => 'if', 'path' => 'order.note', 'children' => [
                    ['type' => 'text', 'value' => 'Note: '], ['type' => 'field', 'path' => 'order.note'], ['type' => 'line'],
                ]],
            ],
        ], ['order' => ['note' => 'No onion']]);

        self::assertSame(['Note: No onion<BR>'], $document['pages']);
    }

    public function testRejectsElementsOutsideConfiguredLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PrintDocumentRenderer())->render([
            'kind' => 'label', 'widthMm' => 30, 'heightMm' => 20,
            'nodes' => [['type' => 'labelText', 'x' => 300, 'y' => 8, 'path' => 'name']],
        ], ['name' => 'Out of bounds']);
    }

    public function testReceiptStyleUsesPairedVendorTagsWithBreakInsideAlignment(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'style', 'align' => 'center', 'width' => 2, 'height' => 2, 'bold' => true, 'children' => [
                ['type' => 'field', 'path' => 'title'],
            ]],
            ['type' => 'style', 'align' => 'right', 'height' => 2, 'children' => [['type' => 'text', 'value' => 'Total'], ['type' => 'line']]],
            ['type' => 'style', 'width' => 2, 'children' => [['type' => 'text', 'value' => 'Wide']]],
        ]], ['title' => '<Demo>']);
        self::assertSame('<C><B><BOLD>&ltDemo&gt<BR></BOLD></B></C><R><HB>Total<BR></HB></R><L><WB>Wide<BR></WB></L>', $document['content']);
    }

    public function testReceiptLoopsDoNotDuplicateHeaderAndFooter(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'text', 'value' => 'HEADER|'],
            ['type' => 'each', 'path' => 'items', 'children' => [
                ['type' => 'repeat', 'countPath' => 'item.quantity', 'children' => [['type' => 'field', 'path' => 'item.name']]],
            ]],
            ['type' => 'text', 'value' => '|FOOTER'],
        ]], ['items' => [['name' => 'Tea', 'quantity' => 2], ['name' => 'Cake', 'quantity' => 1]]]);
        self::assertSame('HEADER|TeaTeaCake|FOOTER', $document['content']);
    }

    public function testAdminDemoDataPopulatesLegacyFieldsAndQuantityLabels(): void
    {
        $json = file_get_contents(__DIR__.'/../../../admin/config/components/printer-sample-data.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $renderer = new PrintDocumentRenderer();
        $receipt = $renderer->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'field', 'path' => 'store.name'], ['type' => 'line'],
            ['type' => 'field', 'path' => 'order.counterName'], ['type' => 'line'],
            ['type' => 'each', 'path' => 'order.items', 'children' => [
                ['type' => 'field', 'path' => 'item.spec'], ['type' => 'text', 'value' => ' '],
                ['type' => 'field', 'path' => 'item.unitPrice'], ['type' => 'line'],
            ]],
            ['type' => 'field', 'path' => 'order.amountDue'],
            ['type' => 'qr', 'template' => 'https://example.invalid/payment?order={order.uuid}'],
        ]], $data);
        foreach (['Niushun Taishan Taicheng Store', 'Outdoor 2 (Small)', 'Beef oyster blade', 'Tofu skin', '2900', '1500', '21100', 'https://example.invalid/payment?order=b49d86b6-4b9e-4b8a-9527-4a34a4a1ed34'] as $value) {
            self::assertStringContainsString($value, $receipt['content']);
        }
        $labels = $renderer->render(['kind' => 'label', 'nodes' => [
            ['type' => 'each', 'path' => 'order.items', 'children' => [
                ['type' => 'repeat', 'countPath' => 'item.quantity', 'children' => [
                    ['type' => 'labelText', 'path' => 'order.labelHeader', 'y' => 24],
                    ['type' => 'labelText', 'path' => 'item.product', 'y' => 96],
                    ['type' => 'labelText', 'path' => 'item.subTitle', 'y' => 168],
                ]],
            ]],
        ]], $data);
        self::assertCount(12, $labels['pages']);
        self::assertStringContainsString('Beef oyster blade', $labels['pages'][0]);
        self::assertStringContainsString('Blanch for 20 seconds', $labels['pages'][0]);
        self::assertStringContainsString('Tofu skin', $labels['pages'][1]);
    }

    public function testLegacyCommandsPreserveUnpairedTagsAndLiteralLineBreaks(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'receiptCommand', 'value' => '<CB>'],
            ['type' => 'field', 'path' => 'counter'],
            ['type' => 'text', 'value' => "\n\n"],
            ['type' => 'receiptCommand', 'value' => '<CB>'],
            ['type' => 'receiptCommand', 'value' => '<B>'],
            ['type' => 'field', 'path' => 'product'],
            ['type' => 'receiptCommand', 'value' => '</B>'],
            ['type' => 'if', 'path' => 'subtitle', 'children' => [
                ['type' => 'text', 'value' => "\n\n"],
                ['type' => 'receiptCommand', 'value' => '<C>'],
                ['type' => 'receiptCommand', 'value' => '<N>'],
                ['type' => 'field', 'path' => 'subtitle'],
            ]],
            ['type' => 'text', 'value' => "\n"],
        ]], ['counter' => 'A01', 'product' => 'Tea', 'subtitle' => 'Less ice']);

        self::assertSame("<CB>A01\n\n<CB><B>Tea</B>\n\n<C><N>Less ice\n", $document['content']);
        self::assertStringNotContainsString('BOLD', $document['content']);
        self::assertStringNotContainsString('<BR>', $document['content']);
    }

    public function testLegacyCommandNodeCannotInjectArbitraryCommandsOrData(): void
    {
        foreach (['<CUT>', '<C>text', '<C><B>', '<QRCODE s=8>', '</BR>', '<script>', "<C>\n"] as $value) {
            try {
                (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
                    ['type' => 'receiptCommand', 'value' => $value],
                ]], []);
                self::fail('Expected invalid receipt command to be rejected.');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'receiptCommand', 'value' => '<CB>'],
            ['type' => 'field', 'path' => 'name'],
        ]], ['name' => '<CUT>']);
        self::assertSame('<CB>&ltCUT&gt', $document['content']);
    }

    public function testLegacyLabelCenteringCountsAllMultibyteCharactersAsFullCells(): void
    {
        $renderer = new PrintDocumentRenderer();
        $node = ['type' => 'labelText', 'path' => 'text', 'align' => 'center', 'widthMode' => 'legacy', 'y' => 24, 'w' => 2, 'h' => 2];
        foreach ([['  A  ', 148], ["\u{00E9}A", 124], ["e\u{0301}", 124], [str_repeat('A', 30), 0], ['', 160]] as [$text, $x]) {
            $document = $renderer->render(['kind' => 'label', 'widthMm' => 40, 'nodes' => [$node]], ['text' => $text]);
            self::assertStringContainsString(sprintf('<TEXT x="%d" y="24"', $x), $document['content']);
            self::assertStringContainsString('>'.$text.'</TEXT>', $document['content']);
        }
        // The default remains Unicode-display-width based for non-legacy templates.
        unset($node['widthMode']);
        $document = $renderer->render(['kind' => 'label', 'nodes' => [$node]], ['text' => "\u{00E9}A"]);
        self::assertStringContainsString('<TEXT x="136"', $document['content']);
    }

    public function testLegacyLabelEnvelopePreservesWhitespaceWithoutAddingSize(): void
    {
        $definition = ['kind' => 'label', 'labelEnvelope' => 'legacy', 'nodes' => [
            ['type' => 'text', 'value' => "\n    "],
            ['type' => 'labelText', 'value' => 'A01', 'align' => 'center', 'widthMode' => 'legacy', 'y' => 24, 'w' => 2, 'h' => 2, 'r' => 0],
            ['type' => 'text', 'value' => "\n"],
        ]];
        $document = (new PrintDocumentRenderer())->render($definition, []);
        self::assertSame("<PAGE>\n    <TEXT x=\"124\" y=\"24\" font=\"9\" w=\"2\" h=\"2\" r=\"0\">A01</TEXT>\n</PAGE>", $document['content']);
        self::assertStringNotContainsString('<SIZE>', $document['content']);
        unset($definition['labelEnvelope']);
        $sized = (new PrintDocumentRenderer())->render($definition, []);
        self::assertStringStartsWith('<PAGE><SIZE>40,30</SIZE>', $sized['content']);
    }

    public function testLabelCentersCjkAndAsciiUsingUnescapedTextAndScale(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'label', 'widthMm' => 40, 'heightMm' => 30, 'nodes' => [
            ['type' => 'labelText', 'path' => 'title', 'align' => 'center', 'y' => 24, 'w' => 2, 'h' => 2],
        ]], ['title' => "\u{4E2D}\u{6587}A"]);
        // Two full-width cells plus an ASCII half-cell: (320 - 5*12*2)/2 = 100 dots.
        self::assertStringContainsString('<TEXT x="100" y="24" font="9" w="2" h="2" r="0">'."\u{4E2D}\u{6587}A".'</TEXT>', $document['content']);
        $escaped = (new PrintDocumentRenderer())->render(['kind' => 'label', 'nodes' => [
            ['type' => 'labelText', 'value' => '<A>', 'align' => 'right', 'r' => 0],
        ]], []);
        self::assertStringContainsString('x="284"', $escaped['content']);
        self::assertStringContainsString('&ltA&gt', $escaped['content']);
    }

    public function testLabelAllowsExplicitRotationWithoutAutomaticAlignment(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'label', 'nodes' => [
            ['type' => 'labelText', 'value' => 'Rotated', 'x' => 8, 'y' => 8, 'w' => 1, 'h' => 2, 'r' => 90],
        ]], []);
        self::assertStringContainsString('w="1" h="2" r="90"', $document['content']);
    }

    public function testReceiptColumnsPadAndTruncateCjkByDisplayCells(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'columns', 'columns' => [
                ['value' => "\u{4E2D}\u{6587}\u{5546}\u{54C1}", 'width' => 5],
                ['path' => 'price', 'width' => 6, 'align' => 'right'],
                ['value' => 'x2', 'width' => 4, 'align' => 'center'],
            ]],
        ]], ['price' => '12.00']);
        self::assertSame("\u{4E2D}\u{6587}  12.00 x2 ", $document['content']);
        self::assertSame(15, mb_strwidth($document['content'], 'UTF-8'));
    }

    public function testQrUsesCorrectCommandsForReceiptAndLabel(): void
    {
        $renderer = new PrintDocumentRenderer();
        $node = ['type' => 'qr', 'path' => 'url', 'size' => 8, 'errorCorrection' => 'M', 'align' => 'right'];
        $receipt = $renderer->render(['kind' => 'receipt', 'nodes' => [$node]], ['url' => 'https://example.invalid/pay?a=1&b=2']);
        self::assertSame('<QRCODE s=8 e=M l=right>https://example.invalid/pay?a=1&b=2</QRCODE>', $receipt['content']);
        $label = $renderer->render(['kind' => 'label', 'nodes' => [['type' => 'qr', 'path' => 'value']]], ['value' => 'hello']);
        self::assertStringContainsString('<QRC x="0" y="0" s="2" e="L" c="1">hello</QRC>', $label['content']);
    }

    public function testStringNodeConcatenatesFieldsLiteralsAndEscapedBraces(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'string', 'value' => '桌台：{{ {order.counterName} }} 单号：{order.number}'],
            ['type' => 'line'],
        ]], ['order' => ['counterName' => 'A01', 'number' => 64]]);
        self::assertSame("桌台：{ A01 } 单号：64<BR>", $document['content']);

        $missing = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'string', 'value' => 'A{order.missing}B'],
        ]], ['order' => []]);
        self::assertSame('AB', $missing['content']);
    }

    public function testStringFiltersFormatMoneyDatesAndText(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'string', 'value' => '{item.price|money} / {item.price|money:0} / {item.price|money:3}'],
            ['type' => 'line'],
            ['type' => 'string', 'value' => '{order.createdAt|date:Y/m/d H:i}'],
            ['type' => 'line'],
            ['type' => 'string', 'value' => '{item.name|trim|upper}|{item.name|lower}|{item.name|trim|truncate:2}|{item.missing|default:none}'],
            ['type' => 'line'],
            ['type' => 'string', 'value' => '{item.missing|money}|{item.missing|date}'],
        ]], [
            'item' => ['price' => 2900, 'name' => ' Tea '],
            'order' => ['createdAt' => '2026-11-12T23:12:23+08:00'],
        ]);
        self::assertSame("29.00 / 2,900 / 2.900<BR>2026/11/12 23:12<BR>TEA| tea |Te|none<BR>|", $document['content']);
    }

    public function testColumnsAndLabelTextAcceptStringTemplates(): void
    {
        $receipt = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'columns', 'columns' => [
                ['template' => '{item.name}', 'width' => 8],
                ['template' => '{item.price|money}', 'width' => 8, 'align' => 'right'],
            ]],
        ]], ['item' => ['name' => 'Tea', 'price' => 2900]]);
        self::assertSame('Tea'.str_repeat(' ', 8).'29.00', $receipt['content']);

        $label = (new PrintDocumentRenderer())->render(['kind' => 'label', 'widthMm' => 40, 'nodes' => [
            ['type' => 'labelText', 'template' => '{order.counterName}-{item.index}', 'align' => 'center', 'y' => 24],
            ['type' => 'labelText', 'template' => '<{order.counterName}>', 'x' => 8, 'y' => 8],
        ]], ['order' => ['counterName' => 'A01'], 'item' => ['index' => 1]]);
        self::assertStringContainsString('A01-1', $label['content']);
        self::assertStringContainsString('&ltA01&gt', $label['content']);
    }

    public function testStringTemplateRejectsInvalidPlaceholdersAndFilters(): void
    {
        $cases = [
            ['{name'],
            ['name}'],
            ['{}'],
            ['{bad path}'],
            ['{name|unknown}'],
            ['{name|money:9}'],
            ['{name|money:abc}'],
            ['{name|money:1}'],
            ['{name|truncate:0}'],
            ['{name|truncate:501}'],
            ['{name|date:Y-m-d}'],
            ['{name|date:' . str_repeat('Y', 41) . '}'],
            [str_repeat('a', 1001)],
        ];
        foreach ($cases as [$value]) {
            try {
                (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
                    ['type' => 'string', 'value' => $value],
                ]], ['name' => 'not-a-date', 'bad' => ['array']]);
                self::fail('Expected invalid string template to be rejected: '.$value);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStringTemplateEscapesMarkup(): void
    {
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'string', 'value' => '<{name}>'],
        ]], ['name' => '<CUT>']);
        self::assertSame('&lt&ltCUT&gt&gt', $document['content']);
    }

    public function testQrAndBarcodeAcceptStringTemplates(): void
    {
        $receipt = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [
            ['type' => 'qr', 'template' => 'https://example.invalid/h5/payment/index.html?orderUuid={order.uuid}&storeUuid={store.uuid}', 'size' => 8],
        ]], ['order' => ['uuid' => 'o-1'], 'store' => ['uuid' => 's-1']]);
        self::assertSame('<QRCODE s=8 e=L l=center>https://example.invalid/h5/payment/index.html?orderUuid=o-1&storeUuid=s-1</QRCODE>', $receipt['content']);

        $label = (new PrintDocumentRenderer())->render(['kind' => 'label', 'nodes' => [
            ['type' => 'barcode', 'template' => 'P-{item.code}', 'height' => 40],
        ]], ['item' => ['code' => 7]]);
        self::assertStringContainsString('<BC128 x="0" y="0" h="40">P-7</BC128>', $label['content']);
    }

    public function testRejectsInvalidLayoutOptions(): void
    {
        $cases = [
            ['kind' => 'receipt', 'nodes' => [['type' => 'style', 'align' => 'justify']]],
            ['kind' => 'receipt', 'nodes' => [['type' => 'style', 'width' => 3]]],
            ['kind' => 'label', 'nodes' => [['type' => 'style']]],
            ['kind' => 'receipt', 'labelEnvelope' => 'legacy', 'nodes' => [['type' => 'text', 'value' => 'x']]],
            ['kind' => 'label', 'labelEnvelope' => 'unknown', 'nodes' => [['type' => 'text', 'value' => 'x']]],
            ['kind' => 'label', 'nodes' => [['type' => 'receiptCommand', 'value' => '<CB>']]],
            ['kind' => 'label', 'nodes' => [['type' => 'labelText', 'value' => 'x', 'align' => 'center', 'widthMode' => 'unknown']]],
            ['kind' => 'label', 'nodes' => [['type' => 'labelText', 'value' => 'x', 'w' => 11]]],
            ['kind' => 'label', 'nodes' => [['type' => 'labelText', 'value' => 'x', 'r' => 45]]],
            ['kind' => 'label', 'nodes' => [['type' => 'labelText', 'value' => 'x', 'align' => 'center', 'r' => 90]]],
            ['kind' => 'receipt', 'nodes' => [['type' => 'qr', 'path' => 'bad']]],
            ['kind' => 'receipt', 'nodes' => [['type' => 'qr', 'path' => 'good', 'size' => 17]]],
            ['kind' => 'receipt', 'nodes' => [['type' => 'columns', 'columns' => [['width' => 97, 'value' => 'x']]]]],
            ['kind' => 'receipt', 'nodes' => [['type' => 'columns', 'columns' => [['width' => 5, 'value' => "x\ny"]]]]],
        ];
        foreach ($cases as $template) {
            try {
                (new PrintDocumentRenderer())->render($template, ['bad' => 'has whitespace', 'good' => 'https://example.invalid']);
                self::fail('Expected invalid layout to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
