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

    /*
    |--------------------------------------------------------------------------
    | Sync Failure Alert
    |--------------------------------------------------------------------------
    | Listen ke event Nawasara\Sync\Events\SyncJobFinalFailed dan kirim
    | notifikasi ke admin saat sync untuk satu (service+action) gagal
    | beruntun sebanyak `consecutive_failures` kali.
    |
    | - enabled: matikan listener tanpa edit code
    | - consecutive_failures: threshold trigger alert
    | - lookback_hours: window untuk hitung beruntun
    | - cooldown_minutes: setelah alert terkirim untuk (service+action) tertentu,
    |   tidak akan kirim alert lagi sampai cooldown lewat — prevent flood
    | - template_key: template yang dipakai (lihat DefaultTemplateSeeder)
    | - admin_emails: list email penerima. Kalau kosong, listener akan no-op.
    */
    'sync_failure_alert' => [
        'enabled' => env('NOTIFICATION_SYNC_ALERT_ENABLED', true),
        'consecutive_failures' => (int) env('NOTIFICATION_SYNC_ALERT_THRESHOLD', 3),
        'lookback_hours' => (int) env('NOTIFICATION_SYNC_ALERT_LOOKBACK_HOURS', 6),
        'cooldown_minutes' => (int) env('NOTIFICATION_SYNC_ALERT_COOLDOWN_MIN', 60),
        'template_key' => 'sync.failed',
        'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('NOTIFICATION_SYNC_ALERT_EMAILS', '')))),
    ],
];
