<?php

namespace Nawasara\Notification\Tests;

use Illuminate\Support\Facades\Blade;
use Nawasara\Notification\Database\Seeders\DefaultTemplateSeeder;
use Nawasara\Notification\Services\TemplateRenderer;
use Tests\TestCase;

/**
 * Audit keamanan 15 Oktober 2026 (S1): renderer Blade diganti renderer
 * placeholder. Template bawaan yang sudah tersimpan di prod tidak disentuh,
 * jadi keluarannya harus SAMA PERSIS dengan Blade dulu, termasuk cabang
 * @if dan baris baru yang dimakan direktif. Uji ini membandingkan keduanya
 * langsung (Blade dipakai di sini hanya sebagai pembanding, pada template
 * bawaan yang isinya kita tulis sendiri).
 */
class DefaultTemplatesRenderSameTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function templates(): array
    {
        $m = new \ReflectionMethod(DefaultTemplateSeeder::class, 'templates');
        $m->setAccessible(true);

        return $m->invoke(new DefaultTemplateSeeder());
    }

    /** Beberapa set data per template supaya setiap cabang @if terlewati. */
    private function dataSets(): array
    {
        $umum = [
            'service' => 'whm', 'consecutive_failures' => 3, 'action' => 'sync_accounts',
            'last_error' => 'cURL error 28: <timeout> & "retry"', 'sync_jobs_url' => 'https://x.test/admin/sync?a=1&b=2',
            'domain' => 'dinkes.ponorogo.go.id', 'expires_at' => '2026-10-20',
            'contact_name' => "O'Brien", 'username' => 'dinkes', 'cpanel_url' => 'https://cpanel.test:2083',
            'quota_mb' => 1024, 'zone' => 'ponorogo.go.id', 'down_since' => '2026-10-15 08:00',
            'name' => 'Budi <Admin>', 'login_url' => 'https://nawasara.test/login',
            'reset_at' => '2026-10-15 09:00',
        ];

        return [
            $umum + ['days' => 3, 'package' => 'Gold', 'temporary_password' => 'Abc&123', 'role' => 'developer',
                'cf_dashboard_url' => 'https://dash.cloudflare.com', 'status_code' => 502, 'check_method' => 'HEAD /'],
            $umum + ['days' => 30, 'package' => '', 'temporary_password' => null],
            $umum + ['days' => 7],
        ];
    }

    public function test_template_bawaan_identik_dengan_blade(): void
    {
        $renderer = new TemplateRenderer();
        $diuji = 0;

        foreach ($this->templates() as $tpl) {
            foreach (['subject', 'body_email_html', 'body_email_text'] as $field) {
                $src = $tpl[$field] ?? null;
                if (! $src) {
                    continue;
                }

                foreach ($this->dataSets() as $i => $data) {
                    $expected = Blade::render($src, $data);
                    $actual = $renderer->renderString($src, $data, true);

                    $this->assertSame($expected, $actual, "{$tpl['key']}.{$field} set #{$i}");
                    $diuji++;
                }
            }
        }

        $this->assertGreaterThan(10, $diuji);
    }
}
