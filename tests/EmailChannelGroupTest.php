<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Channels\EmailChannel;
use PHPUnit\Framework\TestCase;

/**
 * Pemilihan grup Vault SMTP per pengirim: email Akun Kisara keluar dari
 * `smtp-kisara`, sisanya dari `smtp`.
 */
class EmailChannelGroupTest extends TestCase
{
    private function configured(string ...$groups): \Closure
    {
        return fn (string $g) => in_array($g, $groups, true);
    }

    public function test_grup_pilihan_dipakai_bila_lengkap(): void
    {
        $this->assertSame('smtp-kisara', EmailChannel::resolveGroup('smtp-kisara', $this->configured('smtp', 'smtp-kisara')));
    }

    /** Kredensial Kisara belum diisi: email tetap terkirim lewat `smtp`, bukan gagal. */
    public function test_grup_pilihan_belum_lengkap_jatuh_ke_smtp(): void
    {
        $this->assertSame('smtp', EmailChannel::resolveGroup('smtp-kisara', $this->configured('smtp')));
    }

    public function test_tanpa_pilihan_memakai_smtp(): void
    {
        $this->assertSame('smtp', EmailChannel::resolveGroup(null, $this->configured('smtp', 'smtp-kisara')));
    }

    /** Context tidak boleh menunjuk grup Vault lain, meski grup itu lengkap. */
    public function test_grup_di_luar_daftar_diabaikan(): void
    {
        foreach (['whm', 'keycloak', 'minio', ['smtp-kisara'], 42] as $asing) {
            $this->assertSame('smtp', EmailChannel::resolveGroup($asing, fn () => true));
        }
    }
}
