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

        self::assertSame(['Tea<BR>', 'Cake<BR>'], $document['pages']);
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
}
