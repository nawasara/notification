<?php

namespace Nawasara\Notification\Services;

use Illuminate\Support\Facades\Blade;
use Nawasara\Notification\Models\NotificationTemplate;

/**
 * Render template body untuk channel tertentu dengan variable substitution.
 *
 * Menggunakan Blade::render() — full Blade syntax dukung loops, conditional,
 * helper function. Variable diteruskan as $vars di scope template.
 */
class TemplateRenderer
{
    /**
     * Return ['subject' => ..., 'body' => ...] yang sudah ke-render.
     */
    public function render(NotificationTemplate $template, string $channel, array $data = []): array
    {
        $rawBody = $template->bodyFor($channel);
        if (! $rawBody) {
            throw new \InvalidArgumentException("Template '{$template->key}' tidak punya body untuk channel '{$channel}'");
        }

        $rawSubject = $template->subject;

        return [
            'subject' => $rawSubject ? $this->renderString($rawSubject, $data) : null,
            'body' => $this->renderString($rawBody, $data),
        ];
    }

    public function renderString(string $template, array $data = []): string
    {
        try {
            return Blade::render($template, $data);
        } catch (\Throwable $e) {
            // Kalau Blade gagal (e.g. variable undefined), surface error tapi
            // tetap kirim raw template biar admin bisa debug isinya.
            return $template . "\n\n[Template render error: ".$e->getMessage()."]";
        }
    }
}
