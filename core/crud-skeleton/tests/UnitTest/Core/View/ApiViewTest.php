<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use PHPUnit\Framework\TestCase;

final class ApiViewTest extends TestCase
{
    private function createController(): object
    {
        return new class extends RestController {
            use ApiView;

            public function message(): string
            {
                return $this->entityNotFoundMessage();
            }

            public function mix(array $data, array|\Doctrine\ORM\QueryBuilder|null $filter = null): mixed
            {
                return $this->mixToCommonFilter($data, $filter);
            }

            public function mixId(int|string $id, array|\Doctrine\ORM\QueryBuilder|null $filter = null): mixed
            {
                return $this->mixIdToCommonFilter($id, $filter);
            }
        };
    }

    public function testEntityNotFoundMessage(): void
    {
        self::assertSame('Entity not found', $this->createController()->message());
    }

    public function testMixToCommonFilterMergesNumericId(): void
    {
        self::assertSame(['id' => 42], $this->createController()->mix(['id' => 42]));
    }

    public function testMixToCommonFilterMergesUuidField(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        self::assertSame(['uuid' => $uuid], $this->createController()->mix(['uuid' => $uuid]));
    }

    public function testMixIdToCommonFilterMergesWithProvidedFilter(): void
    {
        self::assertSame(['id' => 1, 'storeUuid' => 's1'], $this->createController()->mixId(1, ['storeUuid' => 's1']));
    }

    public function testMixIdToCommonFilterDetectsUuid(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        self::assertSame(['uuid' => $uuid], $this->createController()->mixId($uuid));
    }
}
