<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Channels\TelegramChannel;
use Nawasara\Notification\Services\NotificationPayload;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Bentuk pesan yang sampai di Telegram.
 *
 * Ditulis setelah pesan pertama yang sungguhan terkirim ternyata **kacau**:
 * badan surel berupa tabel HTML dibuang tag-nya, dan yang tersisa adalah
 * kerangkanya — puluhan baris kosong berindentasi dengan angka tercecer di
 * antaranya. Terbaca sebagai pesan rusak, dan justru bagian terpenting (angka
 * beserta artinya) paling sulit ditemukan.
 *
 * Yang diuji: pesan disusun dari DATA, bukan dari badan surel.
 */
class TelegramMessageTest extends TestCase
{
    private function susun(array $ctx, string $body = '', ?string $subject = null): string
    {
        $ch = new TelegramChannel;
        $m = new ReflectionMethod($ch, 'compose');
        $m->setAccessible(true);

        return $m->invoke($ch, new NotificationPayload(
            uuid: 'x', channel: 'telegram', recipient: '-100',
            subject: $subject, body: $body, context: $ctx,
        ));
    }

    private function alert(array $extra = []): array
    {
        return array_merge([
            'rule_key' => 'db.server.aborted_connects_high',
            'kind' => 'fired',
            'severity' => 'warning',
            'alert' => ['label' => 'Kominfo Central MySQL', 'delta' => 11, 'threshold' => 10],
        ], $extra);
    }

    /** Inti perbaikannya: badan surel TIDAK ikut, betapapun berantakannya. */
    public function test_badan_surel_html_tidak_dipakai(): void
    {
        $html = "<table><tr><td>\n\n\n      RESOLVED — sesuatu\n\n\n   </td></tr></table>";
        $pesan = $this->susun($this->alert(), $html);

        $this->assertStringNotContainsString('<table', $pesan);
        $this->assertStringNotContainsString('RESOLVED — sesuatu', $pesan);
        $this->assertStringNotContainsString("\n\n\n", $pesan, 'Tidak boleh ada baris kosong beruntun.');
    }

    /** Angka dan artinya harus terbaca. */
    public function test_nilai_penting_muncul_dengan_label_manusia(): void
    {
        $pesan = $this->susun($this->alert());

        $this->assertStringContainsString('Kominfo Central MySQL', $pesan);
        $this->assertStringContainsString('Kenaikan', $pesan, 'delta harus jadi kata yang dibaca manusia.');
        $this->assertStringNotContainsString('• <b>delta', $pesan);
    }

    public function test_satuan_ditambahkan(): void
    {
        $pesan = $this->susun($this->alert([
            'alert' => ['label' => 'X', 'sisa_gb' => 2, 'percent' => 96],
        ]));

        $this->assertStringContainsString('2 GB', $pesan);
        $this->assertStringContainsString('96%', $pesan);
    }

    /** Tiga keadaan harus dapat dibedakan sekilas. */
    public function test_penanda_berbeda_per_keadaan(): void
    {
        $this->assertStringContainsString('✅', $this->susun($this->alert(['kind' => 'resolved'])));
        $this->assertStringContainsString('🔴', $this->susun($this->alert(['severity' => 'critical'])));
        $this->assertStringContainsString('⚠️', $this->susun($this->alert()));
    }

    /** Batas keras API: melampauinya membuat Telegram menolak SELURUH pesan. */
    public function test_pesan_panjang_dipotong(): void
    {
        $pesan = $this->susun($this->alert([
            'alert' => ['label' => str_repeat('sangat panjang ', 500)],
        ]));

        $this->assertLessThanOrEqual(4096, mb_strlen($pesan));
        $this->assertStringContainsString('dipotong', $pesan);
    }

    /** Pemanggil di luar alerting tetap dilayani, badannya sekadar dirapikan. */
    public function test_tanpa_konteks_alert_pakai_badan_yang_dirapikan(): void
    {
        $pesan = $this->susun([], "<p>Halo</p>\n\n\n\n   <b>dunia</b>", 'Judul');

        $this->assertStringContainsString('Judul', $pesan);
        $this->assertStringContainsString('Halo', $pesan);
        $this->assertStringNotContainsString("\n\n\n", $pesan);
    }
}
