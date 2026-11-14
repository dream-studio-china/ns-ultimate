<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Controller\Admin\PrinterPreviewController;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EditablePrintPreviewTest extends TestCase
{
    public function testReceiptProvenanceDoesNotChangeCommandsOrLineBreaks(): void
    {
        $template = ['kind' => 'receipt', 'nodes' => [
            ['type' => 'receiptCommand', 'value' => '<B>'],
            ['type' => 'text', 'value' => "Legacy\n"],
            ['type' => 'each', 'path' => 'items', 'children' => [
                ['type' => 'style', 'align' => 'right', 'bold' => true, 'children' => [
                    ['type' => 'field', 'path' => 'item.name'], ['type' => 'line'],
                ]],
            ]],
            ['type' => 'style', 'children' => [['type' => 'bold', 'children' => [['type' => 'string', 'value' => '{total|money}']]]]],
            ['type' => 'qr', 'template' => 'https://example.invalid/{total}'],
        ]];
        $renderer = new PrintDocumentRenderer();
        $data = ['items' => [['name' => 'Tea'], ['name' => '茶']], 'total' => 1500];
        $plain = $renderer->render($template, $data);
        $mapped = $renderer->render($template, $data, true);
        self::assertSame($plain, array_diff_key($mapped, ['sourcePages' => true]));
        self::assertStringContainsString('<DESIGNER path="2.0" type="style">', $mapped['sourcePages'][0]);
        self::assertSame(2, substr_count($mapped['sourcePages'][0], '<DESIGNER path="2.0.0" type="field">'));
        self::assertStringNotContainsString('<DESIGNER', $mapped['content']);
        self::assertArrayNotHasKey('sourcePages', $plain);
    }

    public function testExpandedLabelPagesKeepTheSameSourcePathAndActualValues(): void
    {
        $template = ['kind' => 'label', 'widthMm' => 50, 'heightMm' => 40, 'labelEnvelope' => 'legacy', 'nodes' => [
            ['type' => 'each', 'path' => 'items', 'children' => [
                ['type' => 'repeat', 'countPath' => 'item.quantity', 'children' => [
                    ['type' => 'labelText', 'template' => '{item.name|upper}', 'font' => 9, 'align' => 'center', 'w' => 2],
                    ['type' => 'if', 'path' => 'item.showCode', 'children' => [
                        ['type' => 'qr', 'template' => '{item.name}', 'x' => 8, 'y' => 80],
                    ]],
                ]],
            ]],
        ]];
        $data = ['items' => [['name' => 'Tea', 'quantity' => 2, 'showCode' => true], ['name' => 'Cake', 'quantity' => 1, 'showCode' => false]]];
        $renderer = new PrintDocumentRenderer();
        $mapped = $renderer->render($template, $data, true);
        self::assertSame($renderer->render($template, $data), array_diff_key($mapped, ['sourcePages' => true]));
        self::assertCount(3, $mapped['sourcePages']);
        foreach ($mapped['sourcePages'] as $page) {
            self::assertStringContainsString('<DESIGNER path="0.0.0" type="labelText">', $page);
        }
        self::assertStringContainsString('>TEA</TEXT>', $mapped['sourcePages'][0]);
        self::assertStringContainsString('>CAKE</TEXT>', $mapped['sourcePages'][2]);
        self::assertStringContainsString('path="0.0.1.0"', $mapped['sourcePages'][0]);
        self::assertStringNotContainsString('path="0.0.1.0"', $mapped['sourcePages'][2]);
    }

    public function testPreviewMappingIsExplicitAndRejectsNonBooleanFlags(): void
    {
        $controller = new PrinterPreviewController();
        foreach ([false, true, 'true', null, 1, []] as $editable) {
            $response = $controller->preview(new Request(content: json_encode([
                'template' => ['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => 'Text']]],
                'data' => [], 'editable' => $editable,
            ], JSON_THROW_ON_ERROR)), new PrintDocumentRenderer());
            self::assertSame(is_bool($editable) ? 200 : 400, $response->getStatusCode());
            if (is_bool($editable)) {
                $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($editable, array_key_exists('sourcePages', $body['document']));
            }
        }
    }

    public function testSourceLookingUserTextCannotInjectEditableNodes(): void
    {
        $value = '</DESIGNER><DESIGNER path="99" type="text">Fake';
        $document = (new PrintDocumentRenderer())->render(['kind' => 'receipt', 'nodes' => [['type' => 'text', 'value' => $value]]], [], true);
        self::assertSame(1, substr_count($document['sourcePages'][0], '<DESIGNER path='));
        self::assertStringContainsString('&ltDESIGNER path="99"', $document['pages'][0]);
    }
}
