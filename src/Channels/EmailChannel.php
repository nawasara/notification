<?php

namespace Nawasara\Notification\Channels;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Nawasara\Notification\Mail\GenericNotificationMail;
use Nawasara\Notification\Services\NotificationPayload;
use Nawasara\Vault\Facades\Vault;

/**
 * Email channel via Laravel Mail.
 *
 * Credential resolution order:
 *   1. Vault group `smtp` (host + port + encryption + username + password
 *      + from_address + from_name) — kalau ke-set, override mail config
 *      sementara saat send, terus restore ke .env value.
 *   2. Fallback ke .env Laravel (MAIL_MAILER + MAIL_HOST + ...) — pattern
 *      Laravel default; untuk dev pakai 'log' driver.
 *
 * Pattern konsisten dengan service lain (CF, WHM, Keycloak): semua
 * credential yang sensitif di Vault, bisa rotate tanpa redeploy.
 */
class EmailChannel extends AbstractChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function isReady(): bool
    {
        return Vault::isConfigured('smtp') || ! empty(config('mail.default'));
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

        $this->withVaultMailerConfig(function () use ($payload, $mailable) {
            Mail::to($payload->recipient)->send($mailable);
        });

        // Laravel Mail tidak return provider message-id by default. Bisa di-add
        // via Mailgun/SES headers di future.
        return null;
    }

    /**
     * Kalau Vault group `smtp` configured, swap mail config sementara
     * sebelum panggil callback, restore setelah selesai. Ini biar Mail::to(...)
     * pakai SMTP dari Vault, bukan .env.
     */
    protected function withVaultMailerConfig(callable $callback): void
    {
        if (! Vault::isConfigured('smtp')) {
            $callback();
            return;
        }

        $previous = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from' => config('mail.from'),
        ];

        try {
            $vault = $this->vaultCredentials();

            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'host' => $vault['host'],
                'port' => (int) $vault['port'],
                'encryption' => $vault['encryption'] === 'none' ? null : $vault['encryption'],
                'username' => $vault['username'],
                'password' => $vault['password'],
                'timeout' => null,
                'local_domain' => env('MAIL_EHLO_DOMAIN'),
            ]);
            Config::set('mail.from', [
                'address' => $vault['from_address'],
                'name' => $vault['from_name'] ?: config('app.name', 'Nawasara'),
            ]);

            // Bust mailer instance cache supaya pakai config baru
            app('mail.manager')->forgetMailers();

            $callback();
        } finally {
            // Restore config + bust cache lagi
            foreach ($previous as $key => $value) {
                Config::set($key, $value);
            }
            app('mail.manager')->forgetMailers();
        }
    }

    /**
     * Pull SMTP credentials dari Vault.
     *
     * @return array{host:string, port:string, encryption:string, username:string, password:string, from_address:string, from_name:?string}
     */
    protected function vaultCredentials(): array
    {
        return [
            'host' => (string) Vault::get('smtp', 'host'),
            'port' => (string) Vault::get('smtp', 'port'),
            'encryption' => (string) (Vault::get('smtp', 'encryption') ?: 'tls'),
            'username' => (string) Vault::get('smtp', 'username'),
            'password' => (string) Vault::get('smtp', 'password'),
            'from_address' => (string) Vault::get('smtp', 'from_address'),
            'from_name' => Vault::get('smtp', 'from_name'),
        ];
    }

    public function testConnection(): array
    {
        if (Vault::isConfigured('smtp')) {
            $r = $this->testFromVault();
            return ['ok' => $r['success'] ?? false, 'message' => $r['message'] ?? ''];
        }

        if (empty(config('mail.default'))) {
            return ['ok' => false, 'message' => 'SMTP belum di-set di Vault dan MAIL_MAILER tidak di-set di .env'];
        }

        return ['ok' => true, 'message' => 'Pakai .env mail config (driver: '.config('mail.default').')'];
    }

    /**
     * Dipanggil dari Vault credential list "Test Connection" button.
     * TCP handshake ke SMTP host — connect + read greeting + quit, tanpa kirim email.
     *
     * Return format mengikuti convention Vault: {'success': bool, 'message': string}
     */
    public function testFromVault(): array
    {
        if (! Vault::isConfigured('smtp')) {
            return ['success' => false, 'message' => 'Field SMTP belum lengkap di Vault.'];
        }

        $vault = $this->vaultCredentials();

        try {
            $errno = 0;
            $errstr = '';
            $proto = $vault['encryption'] === 'ssl' ? 'ssl://' : '';
            $sock = @fsockopen($proto.$vault['host'], (int) $vault['port'], $errno, $errstr, 5);
            if (! $sock) {
                return ['success' => false, 'message' => "Tidak bisa connect ke {$vault['host']}:{$vault['port']} — {$errstr}"];
            }
            $greeting = fgets($sock, 512);
            fwrite($sock, "QUIT\r\n");
            fclose($sock);

            return ['success' => true, 'message' => 'TCP connect ke SMTP berhasil. Greeting: '.trim((string) $greeting)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
