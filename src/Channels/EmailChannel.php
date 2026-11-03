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
 *   1. Vault group pilihan pengirim lewat context `smtp_group` (mis.
 *      `smtp-kisara`, supaya email Akun Kisara keluar dari kisara@ dan bukan
 *      dari akun pengirim panel) — kalau grup itu lengkap.
 *   2. Vault group `smtp` (host + port + encryption + username + password
 *      + from_address + from_name) — kalau ke-set, override mail config
 *      sementara saat send, terus restore ke .env value.
 *   3. Fallback ke .env Laravel (MAIL_MAILER + MAIL_HOST + ...) — pattern
 *      Laravel default; untuk dev pakai 'log' driver.
 *
 * Pattern konsisten dengan service lain (CF, WHM, Keycloak): semua
 * credential yang sensitif di Vault, bisa rotate tanpa redeploy.
 */
class EmailChannel extends AbstractChannel
{
    public const DEFAULT_GROUP = 'smtp';

    /** Grup Vault yang boleh dipilih lewat context `smtp_group`. */
    public const GROUPS = ['smtp', 'smtp-kisara'];

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
        }, $this->groupFor($payload));

        // Laravel Mail tidak return provider message-id by default. Bisa di-add
        // via Mailgun/SES headers di future.
        return null;
    }

    /**
     * Grup Vault untuk payload ini. Grup pilihan yang belum lengkap jatuh ke
     * `smtp`, supaya email tetap terkirim selama kredensialnya belum diisi.
     */
    public function groupFor(NotificationPayload $payload): string
    {
        return self::resolveGroup(
            $payload->context['smtp_group'] ?? null,
            fn (string $group) => Vault::isConfigured($group),
        );
    }

    /**
     * Nama grup di luar daftar GROUPS diabaikan: context ikut tersimpan di log
     * notifikasi dan bisa datang dari paket mana pun, jadi ia tidak boleh
     * menunjuk grup Vault sembarang (mis. kredensial layanan lain).
     *
     * @param  callable(string): bool  $isConfigured
     */
    public static function resolveGroup(mixed $requested, callable $isConfigured): string
    {
        if (is_string($requested) && in_array($requested, self::GROUPS, true) && $isConfigured($requested)) {
            return $requested;
        }

        return self::DEFAULT_GROUP;
    }

    /**
     * Kalau Vault group SMTP configured, swap mail config sementara
     * sebelum panggil callback, restore setelah selesai. Ini biar Mail::to(...)
     * pakai SMTP dari Vault, bukan .env.
     */
    protected function withVaultMailerConfig(callable $callback, string $group = self::DEFAULT_GROUP): void
    {
        if (! Vault::isConfigured($group)) {
            $callback();

            return;
        }

        $previous = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from' => config('mail.from'),
        ];

        try {
            $vault = $this->vaultCredentials($group);

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
    protected function vaultCredentials(string $group = self::DEFAULT_GROUP): array
    {
        return [
            'host' => (string) Vault::get($group, 'host'),
            'port' => (string) Vault::get($group, 'port'),
            'encryption' => (string) (Vault::get($group, 'encryption') ?: 'tls'),
            'username' => (string) Vault::get($group, 'username'),
            'password' => (string) Vault::get($group, 'password'),
            'from_address' => (string) Vault::get($group, 'from_address'),
            'from_name' => Vault::get($group, 'from_name'),
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
    public function testFromVault(string $group = self::DEFAULT_GROUP): array
    {
        if (! Vault::isConfigured($group)) {
            return ['success' => false, 'message' => 'Field SMTP belum lengkap di Vault.'];
        }

        $vault = $this->vaultCredentials($group);

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

    /** Tombol Test untuk grup Vault `smtp-kisara`. */
    public function testKisaraFromVault(): array
    {
        return $this->testFromVault('smtp-kisara');
    }
}
