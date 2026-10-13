<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Channels\FcmChannel;
use Nawasara\Notification\Services\NotificationPayload;
use PHPUnit\Framework\TestCase;

/**
 * Bagian kanal FCM yang dapat diuji tanpa Firebase: siapa yang boleh jadi
 * penerima, bentuk pesannya, cara galat digolongkan, dan tanda tangan JWT.
 */
class FcmChannelTest extends TestCase
{
    private const TOKEN = 'dQw4w9WgXcQ:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ-hQMRKRrZuJAEcl7B338qju59zJMjw2DELjzEvxwYv7hH5Ynpc1ODQ0aT4U4OFEeco8ohsN5PjL1iC2dNtk2BAokeMCg2ZXKqpc8FXKmhX94kIxQ';

    private function payload(array $context = [], string $body = 'Laporan LB-2026-10-0001 sedang ditangani.'): NotificationPayload
    {
        return new NotificationPayload(
            uuid: 'u-1', channel: 'fcm', recipient: self::TOKEN,
            subject: 'Laporan Anda sedang ditangani', body: $body, context: $context,
        );
    }

    public function test_token_fcm_diterima(): void
    {
        $this->assertTrue((new FcmChannel)->validateRecipient(self::TOKEN));
    }

    /** Alamat surel dan chat id Telegram yang nyasar ke kanal ini harus ditolak, bukan dikirim. */
    public function test_surel_dan_chat_id_ditolak(): void
    {
        $c = new FcmChannel;
        foreach (['warga@gmail.com', '-1001234567890', '123456789', '', 'pendek'] as $bukanToken) {
            $this->assertFalse($c->validateRecipient($bukanToken), "'$bukanToken' bukan token FCM");
        }
    }

    public function test_pesan_membawa_token_judul_isi_dan_prioritas_tinggi(): void
    {
        $m = FcmChannel::buildMessage($this->payload());

        $this->assertSame(self::TOKEN, $m['token']);
        $this->assertSame('Laporan Anda sedang ditangani', $m['notification']['title']);
        $this->assertSame('Laporan LB-2026-10-0001 sedang ditangani.', $m['notification']['body']);
        $this->assertSame('high', $m['android']['priority']);
        $this->assertArrayNotHasKey('data', $m, 'tanpa push_data, kunci data tidak dikirim');
    }

    /** FCM menolak seluruh pesan bila ada nilai data yang bukan string. */
    public function test_nilai_data_selalu_string(): void
    {
        $m = FcmChannel::buildMessage($this->payload([
            'push_data' => ['type' => 'report', 'code' => 'LB-2026-10-0001', 'id' => 42, 'urgent' => true, 'nested' => ['x']],
            'push_channel' => 'laporan',
        ]));

        $this->assertSame(['type' => 'report', 'code' => 'LB-2026-10-0001', 'id' => '42', 'urgent' => '1'], $m['data']);
        $this->assertSame('laporan', $m['android']['notification']['channel_id']);
    }

    public function test_isi_html_dibersihkan_dan_dipotong(): void
    {
        $m = FcmChannel::buildMessage($this->payload([], '<p>Halo</p>   <b>warga</b>'));
        $this->assertSame('Halo warga', $m['notification']['body']);

        $panjang = FcmChannel::buildMessage($this->payload([], str_repeat('a', 5000)));
        $this->assertSame(1000, mb_strlen($panjang['notification']['body']));
    }

    public function test_token_mati_digolongkan_permanen_dan_dibuang(): void
    {
        $e = FcmChannel::classifyError(404, ['error' => [
            'code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]]);

        $this->assertSame('UNREGISTERED', $e['code']);
        $this->assertTrue($e['dead_token']);
        $this->assertTrue($e['permanent']);
    }

    public function test_token_project_lain_dibuang(): void
    {
        $e = FcmChannel::classifyError(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'SenderId mismatch',
            'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]]);
        $this->assertTrue($e['dead_token']);
    }

    /** Token rusak: buang. Pesan kita yang rusak: JANGAN hapus perangkat warga karena kesalahan kita. */
    public function test_invalid_argument_hanya_membuang_token_bila_tokennya_yang_salah(): void
    {
        $token = FcmChannel::classifyError(400, ['error' => ['status' => 'INVALID_ARGUMENT',
            'message' => 'The registration token is not a valid FCM registration token']]);
        $this->assertTrue($token['dead_token']);
        $this->assertTrue($token['permanent']);

        $pesan = FcmChannel::classifyError(400, ['error' => ['status' => 'INVALID_ARGUMENT',
            'message' => 'Invalid value at message.data[0].value']]);
        $this->assertFalse($pesan['dead_token']);
        $this->assertTrue($pesan['permanent']);
    }

    public function test_batas_laju_dan_gangguan_diulang(): void
    {
        foreach ([[429, 'QUOTA_EXCEEDED'], [503, 'UNAVAILABLE'], [500, 'INTERNAL']] as [$status, $kode]) {
            $e = FcmChannel::classifyError($status, ['error' => ['status' => $kode, 'message' => 'x']]);
            $this->assertFalse($e['permanent'], "$kode harus diulang");
            $this->assertFalse($e['dead_token']);
        }

        $kosong = FcmChannel::classifyError(502, []);
        $this->assertSame('HTTP_502', $kosong['code']);
        $this->assertFalse($kosong['permanent']);
    }

    public function test_jwt_ditandatangani_dengan_kunci_service_account(): void
    {
        // PHP di Windows membutuhkan openssl.cnf untuk membuat kunci; Linux tidak.
        $opsi = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($cnf)) {
            $opsi['config'] = $cnf;
        }
        $key = openssl_pkey_new($opsi);
        if ($key === false) {
            $this->markTestSkipped('openssl tidak dapat membuat kunci di lingkungan ini');
        }
        openssl_pkey_export($key, $pem, null, $opsi);
        $public = openssl_pkey_get_details($key)['key'];

        $jwt = FcmChannel::signedAssertion([
            'client_email' => 'nawasara@ponorogo-hub.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], 1_800_000_000);

        [$h, $c, $s] = explode('.', $jwt);
        $dec = fn ($x) => base64_decode(strtr($x, '-_', '+/'));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($dec($h), true));
        $claims = json_decode($dec($c), true);
        $this->assertSame('nawasara@ponorogo-hub.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
        $this->assertSame(1, openssl_verify("$h.$c", $dec($s), $public, OPENSSL_ALGO_SHA256), 'tanda tangan harus sah');
    }

    public function test_hanya_json_service_account_yang_dianggap_terkonfigurasi(): void
    {
        $sah = ['type' => 'service_account', 'project_id' => 'ponorogo-hub',
            'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => '-----BEGIN PRIVATE KEY-----'];

        $this->assertNotNull(FcmChannel::parseServiceAccount($sah));
        $this->assertNull(FcmChannel::parseServiceAccount(null), 'kosong');
        $this->assertNull(FcmChannel::parseServiceAccount(['type' => 'authorized_user'] + $sah), 'bukan service account');
        $this->assertNull(FcmChannel::parseServiceAccount(['private_key' => ''] + $sah), 'kunci kosong');
        // google-services.json milik aplikasi sering salah tempel ke sini.
        $this->assertNull(FcmChannel::parseServiceAccount(['project_info' => ['project_id' => 'x'], 'client' => []]));
    }
}
