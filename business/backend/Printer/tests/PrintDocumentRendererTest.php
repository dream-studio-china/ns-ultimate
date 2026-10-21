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
            ['type' => 'qr', 'path' => 'order.paymentUrl'],
        ]], $data);
        foreach (['Demo tea shop', 'A01', 'Jasmine tea (large)', 'Original cake (slice)', "\u{FFE5}15.00", "\u{FFE5}43.00", 'https://example.invalid/payment?order=1001'] as $value) {
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
        self::assertCount(3, $labels['pages']);
        self::assertStringContainsString('Jasmine tea', $labels['pages'][0]);
        self::assertStringContainsString('Less ice', $labels['pages'][0]);
        self::assertStringContainsString('Original cake', $labels['pages'][2]);
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
