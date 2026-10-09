<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Trade\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Trade\Entity\TradeOutboxMessage;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class TradeOutboxMessageTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $payload = ['orderUuid' => 'order-1', 'totalAmount' => 1000];
        $message = new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'order-1', $payload);

        self::assertNull($message->getId());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $message->getEventId(),
        );
        self::assertSame('trade.order.created.v1', $message->getTopic());
        self::assertSame('order-1', $message->getAggregateId());
        self::assertSame($payload, $message->getPayload());
        self::assertInstanceOf(\DateTimeImmutable::class, $message->getOccurredAt());
        self::assertNull($message->getPublishedAt());
    }

    public function testConstructorMakesAvailableAtEqualToOccurredAt(): void
    {
        $message = new TradeOutboxMessage('trade.order.cancelled.v1', 'trade_order', 'order-2', []);

        $occurredAt = new \ReflectionProperty(TradeOutboxMessage::class, 'occurredAt');
        $availableAt = new \ReflectionProperty(TradeOutboxMessage::class, 'availableAt');

        self::assertSame($occurredAt->getValue($message), $availableAt->getValue($message));
    }

    public function testGetIdReturnsInjectedId(): void
    {
        $message = new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'order-3', []);
        $property = new \ReflectionProperty(TradeOutboxMessage::class, 'id');
        $property->setValue($message, 42);

        self::assertSame(42, $message->getId());
    }

    public function testMarkPublishedSetsPublishedAt(): void
    {
        $message = new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'order-4', []);
        self::assertNull($message->getPublishedAt());

        $message->markPublished();

        $publishedAt = $message->getPublishedAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $publishedAt);
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $publishedAt);
    }

    public function testMarkPublishedCanBeCalledTwice(): void
    {
        $message = new TradeOutboxMessage('trade.order.created.v1', 'trade_order', 'order-5', []);
        $message->markPublished();
        $first = $message->getPublishedAt();
        $message->markPublished();
        $second = $message->getPublishedAt();

        self::assertInstanceOf(\DateTimeImmutable::class, $first);
        self::assertInstanceOf(\DateTimeImmutable::class, $second);
    }
}
