<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default channel
    |--------------------------------------------------------------------------
    |
    | Channel default kalau caller tidak specify. MVP cuma 'email'.
    | Future: 'auto' = pilih berdasarkan user preference + availability.
    */
    'default_channel' => env('NOTIFICATION_DEFAULT_CHANNEL', 'email'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
    'queue_connection' => env('NOTIFICATION_QUEUE_CONNECTION', null), // null = default

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */
    'tries' => env('NOTIFICATION_TRIES', 3),
    'backoff' => [10, 30, 60], // seconds per attempt

    /*
    |--------------------------------------------------------------------------
    | Log retention
    |--------------------------------------------------------------------------
    */
    'log_retention_days' => env('NOTIFICATION_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Channels — registered drivers
    |--------------------------------------------------------------------------
    | MVP: email only. Tambah lebih banyak di phase berikutnya.
    */
    'channels' => [
        'email' => \Nawasara\Notification\Channels\EmailChannel::class,
    ],
];
