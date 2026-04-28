<?php

namespace Nawasara\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $table = 'nawasara_notification_templates';

    protected $fillable = [
        'key', 'name', 'description',
        'subject',
        'body_email_html', 'body_email_text',
        'body_whatsapp', 'body_telegram', 'body_inapp',
        'channels', 'variables',
        'priority', 'active',
    ];

    protected $casts = [
        'channels' => 'array',
        'variables' => 'array',
        'active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    public function supportsChannel(string $channel): bool
    {
        return in_array($channel, $this->channels ?? []);
    }

    public function bodyFor(string $channel): ?string
    {
        return match ($channel) {
            'email' => $this->body_email_html ?: $this->body_email_text,
            'email-text' => $this->body_email_text,
            'whatsapp' => $this->body_whatsapp,
            'telegram' => $this->body_telegram,
            'inapp' => $this->body_inapp,
            default => null,
        };
    }
}
