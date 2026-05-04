<?php

namespace Nawasara\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Nawasara\Notification\Models\NotificationTemplate;

/**
 * Seed default notification templates yang siap dipakai cross-package.
 *
 * Pakai updateOrCreate by `key` — aman di-jalankan ulang, override
 * isi template kalau ada yang ke-update. Kalau admin sudah modify
 * salah satu template manual lewat UI, jangan re-run seeder ini
 * (atau hapus row tertentu dulu).
 */
class DefaultTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            NotificationTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                $tpl,
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function templates(): array
    {
        return [
            [
                'key' => 'sync.failed',
                'name' => 'Sync Job Gagal Beruntun',
                'description' => 'Alert ke admin saat sync job satu service gagal N kali berturut-turut.',
                'subject' => '[Nawasara] Sync {{ $service }} gagal {{ $consecutive_failures }}x berturut-turut',
                'body_email_html' => <<<'HTML'
<p>Sync job untuk service <strong>{{ $service }}</strong> gagal <strong>{{ $consecutive_failures }} kali berturut-turut</strong>.</p>

<p><strong>Action terakhir:</strong> <code>{{ $action }}</code><br>
<strong>Error:</strong></p>
<pre style="background:#fef2f2;padding:8px;border-radius:4px;font-size:12px">{{ $last_error }}</pre>

<p>Cek status sync di <a href="{{ $sync_jobs_url }}">/admin/sync/jobs</a> dan investigate. Sync mungkin akan terus retry tanpa hasil sampai root cause selesai.</p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'service', 'type' => 'string', 'required' => true, 'description' => 'Nama service (whm, cloudflare, keycloak, dst)'],
                    ['name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Action sync yang gagal'],
                    ['name' => 'consecutive_failures', 'type' => 'integer', 'required' => true, 'description' => 'Jumlah kegagalan beruntun'],
                    ['name' => 'last_error', 'type' => 'string', 'required' => true, 'description' => 'Pesan error terakhir'],
                    ['name' => 'sync_jobs_url', 'type' => 'string', 'required' => true, 'description' => 'Link ke halaman sync jobs'],
                ],
                'priority' => 'high',
                'active' => true,
            ],

            [
                'key' => 'ssl.expiry.warning',
                'name' => 'SSL Certificate Akan Expired',
                'description' => 'Warning saat certificate domain mendekati expiration.',
                'subject' => '[Nawasara] SSL {{ $domain }} expired dalam {{ $days }} hari',
                'body_email_html' => <<<'HTML'
<p>Halo,</p>

<p>SSL certificate untuk domain <strong>{{ $domain }}</strong> akan expired dalam <strong>{{ $days }} hari</strong> ({{ $expires_at }}).</p>

@if ($days <= 7)
<p style="color:#dc2626;font-weight:600">⚠️ URGENT — segera renew certificate sebelum service down.</p>
@else
<p>Mohon segera renew sebelum tanggal expiration.</p>
@endif

<p>Cek detail di <a href="{{ $cf_dashboard_url ?? '#' }}">Cloudflare dashboard</a> atau dashboard Nawasara.</p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => 'Nama domain'],
                    ['name' => 'days', 'type' => 'integer', 'required' => true, 'description' => 'Sisa hari sebelum expired'],
                    ['name' => 'expires_at', 'type' => 'string', 'required' => true, 'description' => 'Tanggal expired (formatted)'],
                    ['name' => 'cf_dashboard_url', 'type' => 'string', 'required' => false, 'description' => 'Link ke Cloudflare dashboard'],
                ],
                'priority' => 'high',
                'active' => true,
            ],

            [
                'key' => 'whm.account.welcome',
                'name' => 'WHM Account Baru — Welcome Email',
                'description' => 'Email selamat datang saat akun WHM baru ke-create. Berisi credential awal + cara login.',
                'subject' => '[Nawasara] Akun hosting Anda sudah siap — {{ $domain }}',
                'body_email_html' => <<<'HTML'
<p>Halo {{ $contact_name ?? 'Bapak/Ibu' }},</p>

<p>Akun hosting cPanel untuk domain <strong>{{ $domain }}</strong> sudah berhasil dibuat. Berikut detail akses:</p>

<table style="border-collapse:collapse;margin:12px 0">
    <tr><td style="padding:4px 12px 4px 0"><strong>Username:</strong></td><td style="font-family:monospace">{{ $username }}</td></tr>
    <tr><td style="padding:4px 12px 4px 0"><strong>cPanel URL:</strong></td><td><a href="{{ $cpanel_url }}">{{ $cpanel_url }}</a></td></tr>
    <tr><td style="padding:4px 12px 4px 0"><strong>Quota:</strong></td><td>{{ $quota_mb }} MB</td></tr>
    @if (! empty($package))
    <tr><td style="padding:4px 12px 4px 0"><strong>Package:</strong></td><td>{{ $package }}</td></tr>
    @endif
</table>

<p><strong>Password</strong> sudah dikirim terpisah / atur ulang lewat fitur reset password di cPanel.</p>

<p>Selamat menggunakan layanan hosting Kominfo. Kalau ada kendala silakan hubungi admin Kominfo.</p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'contact_name', 'type' => 'string', 'required' => false, 'description' => 'Nama PIC/penerima'],
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => 'Domain utama'],
                    ['name' => 'username', 'type' => 'string', 'required' => true, 'description' => 'Username cPanel'],
                    ['name' => 'cpanel_url', 'type' => 'string', 'required' => true, 'description' => 'URL login cPanel'],
                    ['name' => 'quota_mb', 'type' => 'integer', 'required' => true, 'description' => 'Disk quota MB'],
                    ['name' => 'package', 'type' => 'string', 'required' => false, 'description' => 'Nama hosting package'],
                ],
                'priority' => 'normal',
                'active' => true,
            ],

            [
                'key' => 'cloudflare.zone.down',
                'name' => 'Cloudflare Zone Down',
                'description' => 'Alert saat zone di Cloudflare terdeteksi down (status check gagal).',
                'subject' => '[Nawasara] Zone {{ $zone }} terdeteksi DOWN',
                'body_email_html' => <<<'HTML'
<p style="color:#dc2626;font-size:16px"><strong>⚠️ Zone {{ $zone }} terdeteksi DOWN</strong></p>

<p>Zone <strong>{{ $zone }}</strong> tidak merespon health check sejak <strong>{{ $down_since }}</strong>.</p>

<p><strong>Last status code:</strong> {{ $status_code ?? 'no response' }}<br>
<strong>Check method:</strong> {{ $check_method ?? 'HTTP GET /' }}</p>

<p>Investigate origin server, atau cek status Cloudflare di <a href="https://www.cloudflarestatus.com/">cloudflarestatus.com</a>.</p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'zone', 'type' => 'string', 'required' => true, 'description' => 'Nama zone/domain'],
                    ['name' => 'down_since', 'type' => 'string', 'required' => true, 'description' => 'Timestamp pertama down'],
                    ['name' => 'status_code', 'type' => 'integer', 'required' => false, 'description' => 'HTTP status code terakhir'],
                    ['name' => 'check_method', 'type' => 'string', 'required' => false, 'description' => 'Metode health check'],
                ],
                'priority' => 'critical',
                'active' => true,
            ],

            [
                'key' => 'user.welcome',
                'name' => 'User Baru — Welcome Email',
                'description' => 'Email saat user baru di-create di Nawasara.',
                'subject' => '[Nawasara] Selamat datang, {{ $name }}',
                'body_email_html' => <<<'HTML'
<p>Halo <strong>{{ $name }}</strong>,</p>

<p>Akun Nawasara Anda sudah aktif. Login lewat:</p>

<p><a href="{{ $login_url }}" style="display:inline-block;padding:8px 16px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px">Login ke Nawasara</a></p>

<p><strong>Username:</strong> {{ $username }}<br>
<strong>Role:</strong> {{ $role ?? '-' }}</p>

<p>Kalau Anda perlu reset password atau ada pertanyaan, hubungi admin Kominfo.</p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Nama user'],
                    ['name' => 'username', 'type' => 'string', 'required' => true, 'description' => 'Username login'],
                    ['name' => 'login_url', 'type' => 'string', 'required' => true, 'description' => 'URL login Nawasara'],
                    ['name' => 'role', 'type' => 'string', 'required' => false, 'description' => 'Role yang di-assign'],
                ],
                'priority' => 'normal',
                'active' => true,
            ],

            [
                'key' => 'user.password_reset',
                'name' => 'User Password Reset',
                'description' => 'Notifikasi setelah password user di-reset oleh admin.',
                'subject' => '[Nawasara] Password Anda telah di-reset',
                'body_email_html' => <<<'HTML'
<p>Halo <strong>{{ $name }}</strong>,</p>

<p>Password akun Nawasara Anda baru saja di-reset oleh administrator pada <strong>{{ $reset_at }}</strong>.</p>

@if (! empty($temporary_password))
<p>Password sementara: <code style="background:#f4f4f5;padding:2px 6px;border-radius:4px;font-family:monospace">{{ $temporary_password }}</code></p>
<p>Mohon segera login dan ganti password Anda.</p>
@else
<p>Silakan login dengan password baru yang dikirim terpisah, atau gunakan link reset password.</p>
@endif

<p><a href="{{ $login_url }}">Login sekarang</a></p>

<p><em>Kalau Anda tidak meminta reset password, segera hubungi admin Kominfo.</em></p>
HTML,
                'channels' => ['email'],
                'variables' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Nama user'],
                    ['name' => 'reset_at', 'type' => 'string', 'required' => true, 'description' => 'Timestamp reset (formatted)'],
                    ['name' => 'temporary_password', 'type' => 'string', 'required' => false, 'description' => 'Password sementara (opsional)'],
                    ['name' => 'login_url', 'type' => 'string', 'required' => true, 'description' => 'URL login Nawasara'],
                ],
                'priority' => 'high',
                'active' => true,
            ],
        ];
    }
}
