<?php

namespace Nawasara\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic mailable — body sudah di-render ke HTML oleh TemplateRenderer.
 * Kita cukup wrap di layout dasar dan kirim.
 *
 * Note: parent Mailable already declares an untyped $subject property,
 * jadi kita pakai field internal $emailSubject + $htmlBody dan tetap pakai
 * Envelope() untuk supply subject.
 */
class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    protected string $emailSubject;
    protected string $htmlBody;

    public function __construct(string $subject, string $htmlBody)
    {
        $this->emailSubject = $subject;
        $this->htmlBody = $htmlBody;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'nawasara-notification::mail.layout',
            with: ['htmlBody' => $this->htmlBody, 'subject' => $this->emailSubject],
        );
    }
}
