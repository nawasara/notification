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

    /**
     * Baris pertama harus menyebut APA YANG TERJADI.
     *
     * Sebelumnya berbunyi "CRITICAL" — itu menyebut seberapa gawat, bukan
     * kejadiannya. Pembaca tetap harus membaca seluruh pesan untuk tahu ini
     * soal serangan, disk penuh, atau agen mati. Di ponsel, saat sedang
     * terjadi sesuatu, baris pertama itulah yang paling menentukan.
     */
    public function test_baris_pertama_menyebut_kejadian_bukan_tingkat_gawat(): void
    {
        $pesan = $this->susun([
            'rule_key' => 'cloudflare.attack.surge',
            'kind' => 'fired',
            'severity' => 'critical',
            'alert' => ['label' => 'perdagkum.ponorogo.go.id'],
        ]);

        $this->assertStringContainsString('SITUS DISERANG', $pesan);
        $this->assertStringNotContainsString('CRITICAL', $pesan);
    }

    /** Tiap jenis kejadian punya sebutannya sendiri. */
    public function test_tiap_jenis_kejadian_punya_sebutan(): void
    {
        $judul = fn (string $key) => $this->susun([
            'rule_key' => $key, 'kind' => 'fired', 'severity' => 'critical',
            'alert' => ['label' => 'x'],
        ]);

        $this->assertStringContainsString('AGEN BERHENTI MELAPOR', $judul('secscan.agent.offline'));
        $this->assertStringContainsString('DISK HAMPIR PENUH', $judul('proxmox.vm.disk.critical'));
        $this->assertStringContainsString('SITUS MATI', $judul('uptime.monitor.down'));
        $this->assertStringContainsString('PROSES LATAR GAGAL', $judul('queue.job.failed'));
    }

    /**
     * Sinkronisasi bernama per layanan, jadi tidak dapat dicocokkan persis.
     */
    public function test_sinkronisasi_dikenali_lewat_awalan(): void
    {
        $pesan = $this->susun([
            'rule_key' => 'sync.job.failed.keycloak', 'kind' => 'fired',
            'severity' => 'warning', 'alert' => ['label' => 'keycloak'],
        ]);

        $this->assertStringContainsString('SINKRONISASI GAGAL', $pesan);
    }

    /**
     * Aturan yang belum dikenali tidak boleh kehilangan judulnya.
     *
     * Jatuh ke deskripsi aturan, supaya aturan baru tetap terbaca meski belum
     * ditambahkan ke peta.
     */
    public function test_aturan_asing_jatuh_ke_deskripsinya(): void
    {
        $pesan = $this->susun([
            'rule_key' => 'sesuatu.yang.baru', 'kind' => 'fired', 'severity' => 'warning',
            'description' => 'Antrean menumpuk', 'alert' => ['label' => 'x'],
        ]);

        $this->assertStringContainsString('ANTREAN MENUMPUK', $pesan);
    }
}
