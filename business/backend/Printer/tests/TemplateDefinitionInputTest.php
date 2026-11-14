<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\TemplateDefinitionInput;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Controller\Admin\PrinterTemplateController;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use PHPUnit\Framework\TestCase;

final class TemplateDefinitionInputTest extends TestCase
{
    public function testObjectAndTextPreserveLiteralNullProperties(): void
    {
        $definition = ['kind' => 'receipt', 'custom' => ['optional' => null], 'nodes' => [
            ['type' => 'if', 'path' => 'order.paidAt', 'equals' => null, 'children' => [['type' => 'text', 'value' => 'Unpaid']]],
        ]];
        self::assertSame($definition, TemplateDefinitionInput::normalize($definition));
        self::assertSame($definition, TemplateDefinitionInput::normalize(json_encode($definition, JSON_THROW_ON_ERROR)));
    }

    public function testInvalidAndOversizedTextFailClosed(): void
    {
        foreach ([null, false, 1, ['receipt'], '[]', 'null', '"receipt"', '{', '{'.str_repeat(' ', 100_000).'}'] as $input) {
            try {
                TemplateDefinitionInput::normalize($input);
                self::fail('Invalid definition was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBothControllerSaveHooksDecodeBeforeRenderingAndEntityAssignment(): void
    {
        $reflection = new \ReflectionClass(PrinterTemplateController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('renderer')->setValue($controller, new PrintDocumentRenderer());
        $entity = new PrinterTemplate();
        $definition = ['kind' => 'receipt', 'custom' => ['keep' => null], 'nodes' => [
            ['type' => 'if', 'path' => 'paidAt', 'equals' => null, 'children' => [['type' => 'text', 'value' => 'Unpaid']]],
        ]];
        foreach (['processCreateContent', 'processUpdateContent'] as $method) {
            $processed = $reflection->getMethod($method)->invoke($controller, [
                'name' => 'Null-safe template', 'kind' => 'receipt',
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                'sampleData' => ['paidAt' => null],
            ], $entity);
            self::assertSame($definition, $processed['definition']);
            self::assertArrayNotHasKey('sampleData', $processed);
            $entity->setDefinition($processed['definition']);
            self::assertSame($definition, $entity->getDefinition());
        }
    }
}
