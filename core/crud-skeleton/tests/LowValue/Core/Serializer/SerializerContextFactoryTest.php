<?php

namespace App\Tests\LowValue\Core\Serializer;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Serializer\SerializerContextFactory;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class SerializerContextFactoryTest extends TestCase
{
    public function testCreateBuildsExpectedContext(): void
    {
        $factory = new SerializerContextFactory();

        $context = $factory->create([
            'groups' => ['a', 'b'],
            'max_depth' => 3,
            'enable_max_depth' => false,
        ]);

        self::assertSame(['a', 'b'], $context['groups']);
        self::assertSame(3, $context['max_depth']);
        self::assertFalse($context['enable_max_depth']);
    }
}
