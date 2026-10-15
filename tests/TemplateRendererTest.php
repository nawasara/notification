<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Audit keamanan 15 Oktober 2026 (S1): template notifikasi yang disunting
 * admin dulu dirender dengan Blade::render(), yaitu eksekusi PHP bagi siapa
 * pun yang memegang izin `notification.template.*`. Uji ini mengunci bahwa
 * renderer pengganti hanya mensubstitusi placeholder dan tidak pernah
 * menjalankan apa pun.
 */
class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $r;

    protected function setUp(): void
    {
        $this->r = new TemplateRenderer();
    }

    // ─── Keamanan ────────────────────────────────────────

    public function test_direktif_php_tidak_dijalankan(): void
    {
        $tpl = "Halo @php file_put_contents(sys_get_temp_dir().'/nawasara-s1-pwn', 'x'); echo 'JALAN'; @endphp";

        $out = $this->r->renderString($tpl, []);

        $this->assertSame($tpl, $out);
        $this->assertFileDoesNotExist(sys_get_temp_dir().'/nawasara-s1-pwn');
    }

    public function test_pemanggilan_fungsi_dalam_echo_tidak_dijalankan(): void
    {
        $tpl = 'A {{ system("echo PWNED") }} B {{ phpinfo() }} C {!! shell_exec("id") !!} D {{ $a . strtoupper($b) }}';

        $out = $this->r->renderString($tpl, ['a' => 'x', 'b' => 'y']);

        // Dibiarkan harfiah, tidak dievaluasi.
        $this->assertSame($tpl, $out);
        $this->assertStringNotContainsString('PWNED'."\n", $out);
    }

    public function test_kondisi_berisi_fungsi_tidak_dijalankan(): void
    {
        $tpl = "@if (system('echo PWNED'))\nYA\n@else\nTIDAK\n@endif\n";

        $this->assertSame("TIDAK\n", $this->r->renderString($tpl, []));
    }

    public function test_nilai_data_tidak_dipindai_ulang(): void
    {
        $data = ['name' => '{{ $secret }} @if ($x) @php echo 1; @endphp', 'secret' => 'RAHASIA'];

        $out = $this->r->renderString('Halo {{ $name }}', $data, false);

        $this->assertSame('Halo {{ $secret }} @if ($x) @php echo 1; @endphp', $out);
        $this->assertStringNotContainsString('RAHASIA', $out);
    }

    public function test_nilai_di_escape_pada_html_dan_mentah_pada_teks(): void
    {
        $data = ['name' => '<script>alert(1)</script> & "x"'];

        $this->assertSame(
            'Halo &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;',
            $this->r->renderString('Halo {{ $name }}', $data, true),
        );
        $this->assertSame(
            'Halo <script>alert(1)</script> & "x"',
            $this->r->renderString('Halo {{ $name }}', $data, false),
        );
        $this->assertSame(
            'Halo <b>x</b>',
            $this->r->renderString('Halo {!! $name !!}', ['name' => '<b>x</b>'], true),
        );
    }

    // ─── Tata bahasa yang didukung ───────────────────────

    public function test_bentuk_placeholder(): void
    {
        $data = ['name' => 'Budi', 'user' => ['opd' => ['nama' => 'Kominfo']], 'obj' => (object) ['role' => 'admin']];

        $this->assertSame('Budi', $this->r->renderString('{{ $name }}', $data));
        $this->assertSame('Budi', $this->r->renderString('{{name}}', $data));
        $this->assertSame('Kominfo', $this->r->renderString('{{ $user.opd.nama }}', $data));
        $this->assertSame('Kominfo', $this->r->renderString('{{ $user->opd->nama }}', $data));
        $this->assertSame('admin', $this->r->renderString('{{ $obj->role }}', $data));
        $this->assertSame('-', $this->r->renderString("{{ \$tidak_ada ?? '-' }}", $data));
        $this->assertSame('', $this->r->renderString('{{ $tidak_ada }}', $data));
        $this->assertSame('{{ $name }}', $this->r->renderString('@{{ $name }}', $data));
        $this->assertSame('ab', $this->r->renderString('a{{-- catatan @php x @endphp --}}b', $data));
    }

    public function test_kondisi(): void
    {
        $tpl = "@if (\$days <= 7)\nURGENT\n@elseif (\$days < 30)\nSEGERA\n@else\nNANTI\n@endif\n";

        $this->assertSame("URGENT\n", $this->r->renderString($tpl, ['days' => 3]));
        $this->assertSame("SEGERA\n", $this->r->renderString($tpl, ['days' => 10]));
        $this->assertSame("NANTI\n", $this->r->renderString($tpl, ['days' => 60]));

        $tpl = "a\n    @if (! empty(\$package))\n    <tr>{{ \$package }}</tr>\n    @endif\nb";
        $this->assertSame("a\n        <tr>Gold</tr>\n    b", $this->r->renderString($tpl, ['package' => 'Gold']));
        $this->assertSame("a\n    b", $this->r->renderString($tpl, []));

        // Seperti Blade, direktif yang menempel pada huruf (Y@endif) bukan direktif.
        $this->assertSame('Y ', $this->r->renderString("@if(\$role == 'admin')Y @endif", ['role' => 'admin']));
        $this->assertSame('', $this->r->renderString('@if(isset($x))Y @endif', []));
    }

    public function test_kondisi_bersarang(): void
    {
        $tpl = "@if (\$a)
A
@if (\$b)
B
@else
-b
@endif
@endif
";

        $this->assertSame("A
B
", $this->r->renderString($tpl, ['a' => 1, 'b' => 1]));
        $this->assertSame("A
-b
", $this->r->renderString($tpl, ['a' => 1, 'b' => 0]));
        $this->assertSame('', $this->r->renderString($tpl, ['a' => 0, 'b' => 1]));
    }

    public function test_struktur_tidak_seimbang_dibiarkan_harfiah(): void
    {
        $tpl = '@if ($a) Halo {{ $name }}';

        $this->assertSame('@if ($a) Halo Budi', $this->r->renderString($tpl, ['a' => 1, 'name' => 'Budi']));
    }

    public function test_alamat_surel_bukan_direktif(): void
    {
        $this->assertSame('kirim ke admin@else.id', $this->r->renderString('kirim ke admin@else.id', []));
    }
}
