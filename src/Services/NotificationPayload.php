<?php

namespace Nawasara\Notification\Services;

/**
 * Immutable value object that travels through the channel pipeline.
 * Built by NotificationService before dispatch.
 */
class NotificationPayload
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly ?int $userId = null,
        public readonly ?int $templateId = null,
        public readonly ?string $templateKey = null,
        public readonly array $context = [],
        public readonly string $priority = 'normal',
    ) {
    }
}
