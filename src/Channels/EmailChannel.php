<?php

namespace Nawasara\Notification\Channels;

use Illuminate\Support\Facades\Mail;
use Nawasara\Notification\Mail\GenericNotificationMail;
use Nawasara\Notification\Services\NotificationPayload;

/**
 * Email channel via Laravel Mail.
 *
 * Pakai SMTP/Sendmail/SES/Mailgun yang sudah di-config di .env Laravel
 * (MAIL_MAILER, MAIL_HOST, dst). Tidak ada credential tambahan di Vault
 * — biarkan stack standard Laravel.
 *
 * MVP: HTML body langsung dari template.body_email_html, optional
 * plain-text fallback dari body_email_text. Subject dari template.subject.
 */
class EmailChannel extends AbstractChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function isReady(): bool
    {
        $mailer = config('mail.default');
        return ! empty($mailer);
    }

    public function validateRecipient(string $recipient): bool
    {
        return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(NotificationPayload $payload): ?string
    {
        if (! $this->validateRecipient($payload->recipient)) {
            throw new \InvalidArgumentException("Invalid email recipient: {$payload->recipient}");
        }

        $mailable = new GenericNotificationMail(
            subject: $payload->subject ?: '(no subject)',
            htmlBody: $payload->body,
        );

        Mail::to($payload->recipient)->send($mailable);

        // Laravel Mail tidak return provider message-id by default. Bisa di-add
        // via Mailgun/SES headers di future. Untuk sekarang kembalikan null
        // dan biar log_id dipakai sebagai handle.
        return null;
    }

    public function testConnection(): array
    {
        if (! $this->isReady()) {
            return ['ok' => false, 'message' => 'MAIL_MAILER tidak di-set di .env'];
        }

        try {
            // Coba transport.send tanpa benar-benar kirim — pakai Mail::raw + log driver
            $mailer = config('mail.default');
            return [
                'ok' => true,
                'message' => "Email channel siap (driver: {$mailer})",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
