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
     * Susun pesan, dan JANGAN pernah melampaui batas API.
     *
     * Melewati 4096 karakter membuat Telegram menolak SELURUH pesan — jadi
     * peringatan yang paling panjang, yang biasanya paling penting, justru
     * yang paling mungkin hilang. Karena itu dipotong di sini, bukan
     * dipasrahkan ke penyedia.
     */
    protected function compose(NotificationPayload $payload): string
    {
        $judul = $payload->subject ? '<b>'.e($payload->subject).'</b>'."\n\n" : '';
        $isi = strip_tags($payload->body);

        $pesan = $judul.e($isi);

        if (mb_strlen($pesan) <= self::MAX_LENGTH) {
            return $pesan;
        }

        $penanda = "\n\n… (dipotong — buka dasbor untuk selengkapnya)";

        return mb_substr($pesan, 0, self::MAX_LENGTH - mb_strlen($penanda)).$penanda;
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
