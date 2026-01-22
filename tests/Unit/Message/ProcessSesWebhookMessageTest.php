<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\ProcessSesWebhookMessage;
use PHPUnit\Framework\TestCase;

final class ProcessSesWebhookMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $messageId = 'test-message-id';
        $notificationType = 'Delivery';
        $payload = [
            'mail' => ['messageId' => $messageId],
            'delivery' => ['timestamp' => '2024-01-01T00:00:00Z'],
        ];

        $message = new ProcessSesWebhookMessage($messageId, $notificationType, $payload);

        self::assertSame($messageId, $message->messageId);
        self::assertSame($notificationType, $message->notificationType);
        self::assertSame($payload, $message->payload);
    }

    public function testEmptyPayload(): void
    {
        $message = new ProcessSesWebhookMessage('id', 'Bounce', []);

        self::assertSame([], $message->payload);
    }

    public function testReadonlyProperties(): void
    {
        $message = new ProcessSesWebhookMessage('id', 'Delivery', []);

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
