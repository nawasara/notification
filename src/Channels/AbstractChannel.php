<?php

namespace Nawasara\Notification\Channels;

use Nawasara\Notification\Contracts\NotificationChannelDriver;

abstract class AbstractChannel implements NotificationChannelDriver
{
    public function isReady(): bool
    {
        return true;
    }

    public function validateRecipient(string $recipient): bool
    {
        return $recipient !== '';
    }

    public function testConnection(): array
    {
        return ['ok' => $this->isReady(), 'message' => $this->isReady() ? 'OK' : 'Not configured'];
    }
}
