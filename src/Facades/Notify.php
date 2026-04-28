<?php

namespace Nawasara\Notification\Facades;

use Illuminate\Support\Facades\Facade;
use Nawasara\Notification\Services\NotificationService;

/**
 * @method static \Nawasara\Notification\Services\NotificationService to(mixed ...$recipients)
 * @method static \Nawasara\Notification\Services\NotificationService channel(string|array $channel)
 * @method static \Nawasara\Notification\Services\NotificationService template(string $key)
 * @method static \Nawasara\Notification\Services\NotificationService data(array $data)
 * @method static \Nawasara\Notification\Services\NotificationService subject(string $subject)
 * @method static \Nawasara\Notification\Services\NotificationService body(string $body)
 * @method static \Nawasara\Notification\Services\NotificationService priority(string $priority)
 * @method static \Nawasara\Notification\Services\NotificationService context(array $context)
 * @method static \Nawasara\Notification\Services\NotificationService sync()
 * @method static array send()
 *
 * @see \Nawasara\Notification\Services\NotificationService
 */
class Notify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NotificationService::class;
    }
}
