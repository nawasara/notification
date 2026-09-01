<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Channels\TelegramChannel;
use PHPUnit\Framework\TestCase;

/**
 * Penjagaan penerima kanal Telegram.
 *
 * Inti masalahnya: sebelum ini, `resolveRecipient()` meloloskan string APA PUN
 * ke kanal APA PUN. Begitu Telegram dinyalakan, alamat surel dari pemanggil
 * lama akan dikirim ke Telegram sebagai "chat id" — gagal di sisi penyedia,
 * tercatat di log yang tak dibaca, dan tidak ada yang tahu peringatannya tak
 * pernah sampai.
 *
 * Kanal yang diam-diam tidak mengirim jauh lebih berbahaya daripada kanal yang
 * jelas-jelas menolak, karena semuanya tampak berjalan. Uji ini mengunci
 * penolakannya.
 */
class TelegramRecipientTest extends TestCase
{
    private TelegramChannel $channel;

    protected function setUp(): void
    {
        $this->channel = new TelegramChannel;
    }

    /** Inti penjagaannya: alamat surel BUKAN chat id. */
    public function test_alamat_surel_ditolak(): void
    {
        foreach (['budi@ponorogo.go.id', 'admin@kominfo.go.id', 'a@b.c'] as $surel) {
            $this->assertFalse(
                $this->channel->validateRecipient($surel),
                "Alamat surel $surel tidak boleh diterima kanal telegram."
            );
        }
    }

    /** Chat id grup selalu negatif — bentuk yang paling sering dipakai. */
    public function test_chat_id_grup_diterima(): void
    {
        $this->assertTrue($this->channel->validateRecipient('-1001234567890'));
        $this->assertTrue($this->channel->validateRecipient('123456789'));
    }

    public function test_username_diterima(): void
    {
        $this->assertTrue($this->channel->validateRecipient('@nawasara_alerts'));
        $this->assertFalse($this->channel->validateRecipient('@'), 'Hanya tanda @ bukan tujuan.');
    }

    public function test_nilai_kosong_dan_ngawur_ditolak(): void
    {
        foreach (['', 'bukan-angka', 'https://t.me/x', '12.34'] as $buruk) {
            $this->assertFalse(
                $this->channel->validateRecipient($buruk),
                "Nilai '$buruk' seharusnya ditolak."
            );
        }
    }

    public function test_nama_kanal(): void
    {
        $this->assertSame('telegram', $this->channel->name());
    }
}
