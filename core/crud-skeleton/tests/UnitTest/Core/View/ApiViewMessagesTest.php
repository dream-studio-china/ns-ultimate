<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\View\ApiViewMessages;
use PHPUnit\Framework\TestCase;

final class ApiViewMessagesTest extends TestCase
{
    public function testConstantsAreDefined(): void
    {
        self::assertSame('SUCCESS', ApiViewMessages::SUCCESS);
        self::assertSame('Entity is not found', ApiViewMessages::ENTITY_NOT_FOUND);
        self::assertSame('Invalid JSON', ApiViewMessages::INVALID_JSON);
        self::assertSame('Invalid content field', ApiViewMessages::INVALID_CONTENT_FIELD);
        self::assertSame('Create failed', ApiViewMessages::CREATE_FAILED);
        self::assertSame('Batch update error', ApiViewMessages::BATCH_UPDATE_ERROR);
        self::assertSame('Content type error.', ApiViewMessages::CONTENT_TYPE_ERROR);
        self::assertSame('Current transition cannot be applied.', ApiViewMessages::TRANSITION_CANNOT_APPLY);
    }

    public function testPropertyRequiredCapitalizesProperty(): void
    {
        self::assertSame('Name is required', ApiViewMessages::propertyRequired('name'));
        self::assertSame('Name is required', ApiViewMessages::propertyRequired('Name'));
    }

    public function testPropertyCannotBeEmptyCapitalizesProperty(): void
    {
        self::assertSame('Nickname cannot be empty.', ApiViewMessages::propertyCannotBeEmpty('nickname'));
        self::assertSame('Nickname cannot be empty.', ApiViewMessages::propertyCannotBeEmpty('Nickname'));
    }
}
