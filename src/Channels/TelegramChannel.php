<?php

namespace Nawasara\Notification\Channels;

use Illuminate\Support\Facades\Http;
use Nawasara\Notification\Services\NotificationPayload;
use Nawasara\Vault\Facades\Vault;

/**
 * Mengirim peringatan ke grup Telegram, dipisah per TOPIK.
 *
 * ## Kenapa Telegram, padahal surel sudah jalan
 *
 * Bukan soal kecepatan. Yang menentukan adalah **pemisahan**: di kotak masuk,
 * 4 peringatan `critical` tampak sama persis dengan 405 `warning` di
 * sekelilingnya — satu baris subjek di antara ratusan. Grup ber-topik membuat
 * yang genting punya tempatnya sendiri, dan itu perbedaan yang benar-benar
 * dirasakan saat kejadian.
 *
 * ## Penerima = chat id, BUKAN alamat surel
 *
 * `recipient` pada payload harus berupa chat id Telegram (angka, boleh negatif
 * untuk grup) atau `@username`. Alamat surel yang nyasar ke sini ditolak
 * [self::validateRecipient] dan dicatat oleh NotificationService — sengaja
 * berisik, karena kanal yang diam-diam tidak mengirim jauh lebih berbahaya
 * daripada kanal yang jelas-jelas menolak.
 *
 * ## Topik (Forum Topics)
 *
 * Grup harus **supergroup** dengan Topics aktif, dan bot menjadi admin. Tiap
 * topik punya `message_thread_id`; pemetaannya dibaca dari konteks payload
 * (`telegram_topic`) lalu dicocokkan ke Vault. Bila topiknya tidak dikenali,
 * pesan tetap dikirim ke topik umum — sebuah peringatan yang mendarat di topik
 * yang keliru masih jauh lebih baik daripada yang tidak terkirim sama sekali.
 */
class TelegramChannel extends AbstractChannel
{
    /** Batas keras Bot API. Pesan yang melampauinya ditolak seluruhnya. */
    protected const MAX_LENGTH = 4096;

    public function name(): string
    {
        return 'telegram';
    }

    public function isReady(): bool
    {
        return Vault::isConfigured('telegram')
            && (string) Vault::get('telegram', 'bot_token') !== '';
    }

    /**
     * Chat id (mis. `-1001234567890`) atau `@username`.
     *
     * Penjagaan ini yang menghentikan alamat surel merembes ke Telegram saat
     * kanalnya dinyalakan untuk pemanggil yang masih mengirim string surel.
     */
    public function validateRecipient(string $recipient): bool
    {
        if ($recipient === '' || str_contains($recipient, '@') && ! str_starts_with($recipient, '@')) {
            return false;   // alamat surel
        }

        return str_starts_with($recipient, '@')
            ? strlen($recipient) > 1
            : preg_match('/^-?\d+$/', $recipient) === 1;
    }

    public function send(NotificationPayload $payload): ?string
    {
        if (! $this->isReady()) {
            throw new \RuntimeException('Telegram belum dikonfigurasi di Vault (grup: telegram).');
        }

        $token = (string) Vault::get('telegram', 'bot_token');

        $params = [
            'chat_id' => $payload->recipient,
            'text' => $this->compose($payload),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($threadId = $this->threadIdFor($payload)) {
            $params['message_thread_id'] = $threadId;
        }

        $response = Http::timeout(15)
            ->asForm()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $params);

        if (! $response->successful() || ($response->json('ok') !== true)) {
            // Pesan galat Telegram justru yang paling menolong saat menyiapkan
            // ("chat not found", "message thread not found"), jadi dibawa apa
            // adanya ke log alih-alih diringkas jadi "gagal kirim".
            throw new \RuntimeException(
                'Telegram menolak: '.($response->json('description') ?? $response->body())
            );
        }

        return (string) $response->json('result.message_id');
    }

    /**
     * Topik tujuan, dari konteks payload.
     *
     * Pemetaannya di Vault, bukan di kode: menambah topik tidak boleh menuntut
     * rilis paket.
     */
    protected function threadIdFor(NotificationPayload $payload): ?int
    {
        $topic = $payload->context['telegram_topic'] ?? null;

        if (! is_string($topic) || $topic === '') {
            return null;
        }

        $id = Vault::get('telegram', 'topic_'.$topic);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Susun pesan yang enak dibaca di PONSEL.
     *
     * ⚠️ Badan surel TIDAK dipakai apa adanya. Ia berupa tabel HTML, dan
     * membuang tag-nya (`strip_tags`) hanya menyisakan kerangkanya: puluhan
     * baris kosong berindentasi dengan nilai-nilai tercecer di antaranya.
     * Terbaca sebagai pesan rusak, dan justru yang paling penting — angka
     * beserta artinya — paling sulit ditemukan.
     *
     * Jadi pesannya disusun ulang dari DATA di konteks. Kalau konteksnya tidak
     * ada (pemanggil di luar alerting), barulah badan surel dipakai setelah
     * dirapikan seadanya.
     *
     * Ringkas dengan sengaja: yang dibutuhkan di ponsel adalah "perlu saya
     * lihat sekarang?", bukan seluruh buktinya. Rinciannya tetap di dasbor.
     */
    protected function compose(NotificationPayload $payload): string
    {
        $ctx = $payload->context;

        $pesan = isset($ctx['rule_key'])
            ? $this->composeFromAlert($payload, $ctx)
            : $this->composeFallback($payload);

        if (mb_strlen($pesan) <= self::MAX_LENGTH) {
            return $pesan;
        }

        $penanda = "\n\n… (dipotong — buka dasbor untuk selengkapnya)";

        return mb_substr($pesan, 0, self::MAX_LENGTH - mb_strlen($penanda)).$penanda;
    }

    /**
     * Pesan untuk peringatan, disusun dari data.
     *
     * @param  array<string,mixed>  $ctx
     */
    protected function composeFromAlert(NotificationPayload $payload, array $ctx): string
    {
        $kind = $ctx['kind'] ?? 'fired';
        $severity = $ctx['severity'] ?? 'warning';

        $ikon = match (true) {
            $kind === 'resolved' => '✅',
            $severity === 'critical' => '🔴',
            $severity === 'info' => 'ℹ️',
            default => '⚠️',
        };

        // Kepala pesan menyebut APA YANG TERJADI, bukan tingkat gawatnya.
        //
        // "CRITICAL" tidak memberi tahu apa pun: pembaca tetap harus membaca
        // seluruh pesan untuk tahu ini soal serangan, disk penuh, atau agen
        // mati. Yang dibutuhkan di ponsel adalah satu baris yang sudah
        // menjawab "ada apa" — tingkat gawatnya sudah tersirat dari ikonnya.
        $kepala = match ($kind) {
            'resolved' => 'PULIH',
            'renotified' => 'MASIH BERLANGSUNG',
            default => $this->judulKejadian($ctx, $severity),
        };

        $baris = [$ikon.' <b>'.e($kepala).'</b>'];

        if ($judul = $ctx['alert']['label'] ?? $ctx['description'] ?? null) {
            $baris[] = e((string) $judul);
        }

        $baris[] = '';

        // Nilai yang menjelaskan KENAPA berbunyi. Kunci teknis (id, kode
        // internal) dilewati — di ponsel ia hanya memenuhi layar.
        $lewati = ['label', 'telegram_topic', 'alert_state_id'];

        foreach (($ctx['alert'] ?? []) as $k => $v) {
            if (in_array($k, $lewati, true) || is_array($v) || $v === null || $v === '') {
                continue;
            }

            $nilai = (string) $v;

            if (str_ends_with($k, '_gb')) {
                $nilai .= ' GB';
            } elseif ($k === 'percent') {
                $nilai .= '%';
            }

            $baris[] = '• <b>'.e(self::labelUntuk($k)).':</b> '.e($nilai);
        }

        $baris[] = '';

        // Tautan dasbor, BUKAN `rule_key`.
        //
        // Kunci aturan seperti `cloudflare.attack.surge` adalah penanda
        // internal: ia tidak berarti apa pun bagi yang membaca di ponsel, dan
        // menempatkannya di akhir pesan hanya membuat baris terakhir terlihat
        // seperti galat. Yang berguna di situ adalah cara MELIHAT lebih
        // lanjut.
        // function_exists: kelas ini juga diuji tanpa container Laravel, dan
        // memanggil config() di sana melempar "Target class [config] does not
        // exist" — menggagalkan uji yang sama sekali tidak menyoal tautan.
        $dasbor = function_exists('config') && app()->bound('config')
            ? rtrim((string) config('app.url'), '/')
            : '';

        if ($dasbor !== '') {
            $baris[] = $dasbor.'/alerting/states';
        }

        if ($kind === 'renotified' && ! empty($ctx['fire_count'])) {
            $baris[] = '<i>pemberitahuan ke-'.(int) $ctx['fire_count'].'</i>';
        }

        return implode("\n", $baris);
    }

    /**
     * Satu baris yang sudah menjawab "ada apa".
     *
     * Diturunkan dari `rule_key`, karena kunci itulah yang benar-benar
     * menyebut jenis kejadiannya — sedangkan severity hanya menyebut seberapa
     * gawat, dan "CRITICAL" sendirian tidak membedakan serangan dari disk
     * penuh.
     *
     * Kunci yang belum dikenali jatuh ke deskripsi aturannya, lalu ke severity
     * sebagai upaya terakhir — supaya aturan baru tetap terbaca meski belum
     * ditambahkan di sini.
     *
     * @param  array<string,mixed>  $ctx
     */
    protected function judulKejadian(array $ctx, string $severity): string
    {
        $key = (string) ($ctx['rule_key'] ?? '');

        $peta = [
            'cloudflare.attack.surge' => 'SITUS DISERANG',
            'cloudflare.ssl.critical' => 'SERTIFIKAT HAMPIR HABIS',
            'cloudflare.ssl.warning' => 'SERTIFIKAT AKAN HABIS',
            'secscan.agent.offline' => 'AGEN BERHENTI MELAPOR',
            'secscan.ip.autoblocked' => 'IP DIBLOKIR OTOMATIS',
            'secscan.site.compromised' => 'SITUS TERKOMPROMI',
            'secscan.site.suspicious' => 'SITUS MENCURIGAKAN',
            'proxmox.vm.disk.critical' => 'DISK HAMPIR PENUH',
            'proxmox.vm.disk.warning' => 'DISK MULAI PENUH',
            'proxmox.node.disk.critical' => 'DISK NODE HAMPIR PENUH',
            'proxmox.node.disk.warning' => 'DISK NODE MULAI PENUH',
            'proxmox.node.memory.critical' => 'MEMORI NODE HAMPIR HABIS',
            'proxmox.node.memory.warning' => 'MEMORI NODE TINGGI',
            'uptime.monitor.down' => 'SITUS MATI',
            'queue.job.failed' => 'PROSES LATAR GAGAL',
        ];

        if (isset($peta[$key])) {
            return $peta[$key];
        }

        // Kegagalan sinkronisasi bernama per layanan (sync.job.failed.whm),
        // jadi tidak dapat dicocokkan persis.
        if (str_starts_with($key, 'sync.job.failed')) {
            return 'SINKRONISASI GAGAL';
        }

        if (str_starts_with($key, 'db.server.')) {
            return 'BASIS DATA BERMASALAH';
        }

        $deskripsi = $ctx['description'] ?? null;

        return is_string($deskripsi) && $deskripsi !== ''
            ? mb_strtoupper($deskripsi)
            : mb_strtoupper($severity);
    }

    /**
     * Nama kolom → kata yang dibaca manusia.
     *
     * `sisa_gb` dan `occurrences` masuk akal bagi yang menulis kodenya, tetapi
     * peringatan ini dibaca di ponsel, sering oleh orang yang tidak menulis
     * aturannya — dan kadang tengah malam. Nama yang tidak dikenali dibiarkan
     * apa adanya, sekadar diganti garis bawahnya dengan spasi.
     */
    protected static function labelUntuk(string $kunci): string
    {
        return [
            'percent' => 'Terpakai (%)',
            'sisa_gb' => 'Sisa',
            'used_gb' => 'Terpakai',
            'total_gb' => 'Kapasitas',
            'threshold' => 'Ambang',
            'delta' => 'Kenaikan',
            'current' => 'Sekarang',
            'previous' => 'Sebelumnya',
            'server' => 'Server',
            'jenis' => 'Jenis',
            'ip' => 'IP',
            'reason' => 'Alasan',
            'score' => 'Skor',
            'occurrences' => 'Kejadian',
            'agent' => 'Agen',
            'service' => 'Layanan',

            // Deteksi serangan. `bermusuhan` sengaja tidak ditampilkan sebagai
            // kata itu — di ponsel ia terbaca seperti istilah teknis; yang
            // dicari pembaca adalah "berapa banyak yang ditahan".
            // Istilah Cloudflare dipakai apa adanya — itu yang tertulis di
            // dasbor CF, sehingga angkanya dapat dicocokkan langsung ke sana.
            // Terjemahan bikinan justru memutus kaitan itu.
            'mitigated' => 'Mitigated (ditahan)',
            'blocked' => 'Blocked (ditolak)',
            'managed_challenge' => 'Managed Challenge',
            'baseline' => 'Baseline (biasanya)',
            'lonjakan' => 'Kenaikan',
            'top_country' => 'Top country',
            'window' => 'Rentang',
            'diam_selama' => 'Sudah diam',
            'perintah_tertahan' => 'Perintah blokir tertahan',
            'action' => 'Aksi',
            'error_short' => 'Galat',
            'attempts' => 'Percobaan',
        ][$kunci] ?? ucfirst(str_replace('_', ' ', $kunci));
    }

    /**
     * Untuk pemanggil di luar alerting: rapikan badan surel seadanya.
     *
     * Bukan sekadar `strip_tags`: baris kosong beruntun dan indentasi sisa
     * tata letak HTML ikut dibuang, karena itulah yang membuat pesannya
     * terlihat rusak.
     */
    protected function composeFallback(NotificationPayload $payload): string
    {
        $isi = html_entity_decode(strip_tags($payload->body), ENT_QUOTES | ENT_HTML5);
        $isi = preg_replace('/[ \t]+/', ' ', $isi);
        $isi = preg_replace('/\n{3,}/', "\n\n", $isi);
        $isi = implode("\n", array_map('trim', explode("\n", (string) $isi)));
        $isi = trim(preg_replace('/\n{3,}/', "\n\n", $isi));

        $judul = $payload->subject ? '<b>'.e($payload->subject).'</b>'."\n\n" : '';

        return $judul.e($isi);
    }

    /**
     * ⚠️ Mengembalikan kunci `success`, BUKAN `ok`.
     *
     * UI Vault membaca `success`; memakai `ok` menghasilkan toast merah
     * meskipun pesannya berbunyi "Terhubung" (AGENTS.md §3). Jangan diseragamkan
     * dengan kunci `ok` milik Telegram di atas — keduanya kebetulan sama
     * namanya dan artinya berbeda.
     */
    public function testConnection(): array
    {
        if (! $this->isReady()) {
            return ['success' => false, 'message' => 'Bot token belum diisi.'];
        }

        try {
            $token = (string) Vault::get('telegram', 'bot_token');
            $me = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");

            if ($me->json('ok') !== true) {
                return ['success' => false, 'message' => 'Token ditolak: '.($me->json('description') ?? 'tidak dikenali')];
            }

            $nama = $me->json('result.username');
            $chatId = (string) Vault::get('telegram', 'chat_id');

            if ($chatId === '') {
                return ['success' => true, 'message' => "Token sah (@{$nama}), tetapi chat id belum diisi."];
            }

            // Kirim betulan: token yang sah tidak menjamin bot ada di grup itu,
            // dan itu justru kekeliruan penyiapan yang paling sering terjadi.
            $uji = Http::timeout(15)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => 'Uji koneksi Nawasara — bila pesan ini terlihat, penyiapannya berhasil.',
            ]);

            if ($uji->json('ok') !== true) {
                return ['success' => false, 'message' => 'Token sah, tetapi gagal mengirim: '.($uji->json('description') ?? '?')];
            }

            return ['success' => true, 'message' => "Terhubung sebagai @{$nama}, pesan uji terkirim."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Gagal: '.$e->getMessage()];
        }
    }
}
