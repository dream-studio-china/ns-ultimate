<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\DependencyInjection;

use App\Core\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testGetConfigTreeBuilderReturnsTreeBuilder(): void
    {
        $config = new Configuration();

        self::assertInstanceOf(TreeBuilder::class, $config->getConfigTreeBuilder());
    }

    public function testRootNodeIsNamedCore(): void
    {
        $config = new Configuration();

        self::assertSame('core', $config->getConfigTreeBuilder()->buildTree()->getName());
    }

    public function testEmptyConfigurationProcessesToEmptyArray(): void
    {
        $config = new Configuration();

        $processed = (new Processor())->processConfiguration($config, []);

        self::assertSame([], $processed);
    }

    public function testUnknownOptionIsRejected(): void
    {
        $config = new Configuration();

        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration($config, ['unknown_option' => 'value']);
    }
}
