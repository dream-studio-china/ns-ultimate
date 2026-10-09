<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Setting;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SettingTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Setting('site_name');

        self::assertSame('site_name', $entity->getKey());
        self::assertNull($entity->getValue());
        self::assertSame('string', $entity->getType());
        self::assertNull($entity->getGroupName());
        self::assertNull($entity->getLabel());
        self::assertNull($entity->getDescription());
        self::assertSame(0, $entity->getSortOrder());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('site_name', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $entity = new Setting('key');

        $entity->setKey('new_key')->setValue('some value')->setType('integer')
            ->setGroupName('general')->setLabel('Site Name')->setDescription('The site name')
            ->setSortOrder(10);

        self::assertSame('new_key', $entity->getKey());
        self::assertSame('some value', $entity->getValue());
        self::assertSame('integer', $entity->getType());
        self::assertSame('general', $entity->getGroupName());
        self::assertSame('Site Name', $entity->getLabel());
        self::assertSame('The site name', $entity->getDescription());
        self::assertSame(10, $entity->getSortOrder());
    }

    public function testTangentUpdatesTimestamp(): void
    {
        $entity = new Setting('key');
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testNullableFieldsAcceptNull(): void
    {
        $entity = new Setting('key');
        $entity->setValue('val')->setGroupName('group')->setLabel('label')->setDescription('desc');

        $entity->setValue(null)->setGroupName(null)->setLabel(null)->setDescription(null);

        self::assertNull($entity->getValue());
        self::assertNull($entity->getGroupName());
        self::assertNull($entity->getLabel());
        self::assertNull($entity->getDescription());
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Setting::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Setting('preserve');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
