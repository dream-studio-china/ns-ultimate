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
}
