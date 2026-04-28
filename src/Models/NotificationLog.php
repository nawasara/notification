<?php

namespace Nawasara\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $table = 'nawasara_notification_logs';

    protected $fillable = [
        'uuid', 'template_id', 'template_key',
        'channel', 'user_id', 'recipient',
        'subject', 'body',
        'status', 'error', 'attempts',
        'provider_message_id',
        'sent_at', 'delivered_at', 'read_at',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';

    public function template()
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function user()
    {
        $userModel = config('auth.providers.users.model');
        return $userModel ? $this->belongsTo($userModel, 'user_id') : null;
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_BOUNCED]);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function markSending(): void
    {
        $this->update(['status' => self::STATUS_SENDING, 'attempts' => $this->attempts + 1]);
    }

    public function markSent(?string $providerId = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'provider_message_id' => $providerId,
            'error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    public function markBounced(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_BOUNCED,
            'error' => $reason,
        ]);
    }
}
