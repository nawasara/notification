<?php

namespace Nawasara\Notification\Contracts;

use Nawasara\Notification\Services\NotificationPayload;

/**
 * Contract setiap channel driver. Email, WA, Telegram, dll implement ini.
 *
 * Channel driver punya satu tanggungjawab: kirim payload yang sudah ter-render
 * via provider mereka, return provider message ID atau throw exception.
 *
 * Logging + retry + queue handled by NotificationService — driver tidak peduli.
 */
interface NotificationChannelDriver
{
    /** Channel identifier ('email', 'whatsapp', 'telegram', dst). */
    public function name(): string;

    /**
     * Apakah driver siap kirim — credential ter-set, service reachable.
     */
    public function isReady(): bool;

    /**
     * Validate format recipient — email valid? phone format? chat_id integer?
     */
    public function validateRecipient(string $recipient): bool;

    /**
     * Kirim payload. Return provider message ID kalau ada (untuk tracking webhook nanti).
     * Throw exception kalau gagal — caller akan log ke notification_log.
     */
    public function send(NotificationPayload $payload): ?string;

    /**
     * Test connectivity / config validity. Return ['ok' => bool, 'message' => string].
     */
    public function testConnection(): array;
}
