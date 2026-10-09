<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Entity;

use App\Inventory\Entity\Material;
use PHPUnit\Framework\TestCase;

final class MaterialTest extends TestCase
{
    public function testCodeCannotChangeAfterStockMutation(): void
    {
        $material = new Material('flour', 'Flour', Material::KIND_RAW, 'kg');
        self::assertTrue($material->isActive());
        $material->markStockMutated();

        $this->expectException(\LogicException::class);
        $material->setCode('new-flour');
    }
}
