<?php

namespace Nawasara\Notification\Tests;

use Nawasara\Notification\Services\NotificationService;
use Nawasara\Notification\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Badan dan subjek ad-hoc TIDAK BOLEH dikompilasi sebagai Blade.
 *
 * Badan ad-hoc sering memuat teks dari luar: nama situs WordPress yang diretas,
 * judul laporan warga, topik rapat Zoom. Bila dilewatkan ke Blade::render(),
 * `{{ ... }}` dan `@php` di dalamnya dijalankan di server. Uji ini mengunci
 * bahwa jalur ad-hoc tidak pernah menyentuh renderer sama sekali.
 */
class AdHocBodyNotCompiledTest extends TestCase
{
    private function render(string $subject, string $body): array
    {
        $renderer = new class extends TemplateRenderer
        {
            public function renderString(string $template, array $data = []): string
            {
                throw new \LogicException('Jalur ad-hoc tidak boleh memanggil renderer.');
            }
        };

        $service = (new NotificationService($renderer))->subject($subject)->body($body);

        $method = new \ReflectionMethod($service, 'renderForChannel');
        $method->setAccessible(true);

        return $method->invoke($service, null, 'email', 'petugas@ponorogo.go.id')[0];
    }

    public function test_ekspresi_blade_tetap_harfiah(): void
    {
        $body = 'Situs <b>{{ system(chr(105).chr(100)) }}</b> diretas';
        $subject = 'Peringatan: {{ phpinfo() }}';

        $rendered = $this->render($subject, $body);

        $this->assertSame($body, $rendered['body']);
        $this->assertSame($subject, $rendered['subject']);
    }

    public function test_direktif_php_tetap_harfiah(): void
    {
        $body = "Judul laporan: @php echo 'x'; @endphp dan {!! \$secret !!}";

        $this->assertSame($body, $this->render('Laporan baru', $body)['body']);
    }
}
