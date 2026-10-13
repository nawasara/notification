<?php

namespace Nawasara\Notification\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * FCM said this device token is dead (app uninstalled, data cleared, token
 * rotated) or malformed.
 *
 * This package does not know where tokens are stored, so it only announces
 * the fact. Whoever owns the token table (nawasara/citizen) listens and
 * deletes the row; otherwise every later notification would try it again.
 */
class PushTokenRejected
{
    use Dispatchable;

    public function __construct(
        public readonly string $token,
        public readonly string $reason,
    ) {}
}
