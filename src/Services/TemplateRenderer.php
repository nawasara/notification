<?php

namespace Nawasara\Notification\Services;

use Nawasara\Notification\Models\NotificationTemplate;

/**
 * Render template notifikasi dengan substitusi placeholder yang AMAN.
 *
 * ⚠️ Audit keamanan 15 Oktober 2026 (S1): dulu kelas ini memakai
 * Blade::render() pada subjek dan badan template. Template disunting admin
 * lewat UI, dan Blade adalah PHP: `@php system('id') @endphp` atau
 * `{{ system('id') }}` berjalan di server saat pratinjau maupun saat kirim.
 * Izin `notification.template.*` dapat diberikan ke role mana pun lewat UI,
 * jadi "hanya developer" bukan batas yang dapat diandalkan.
 *
 * Sekarang template TIDAK PERNAH dikompilasi. Yang dikenali hanya bentuk
 * berikut, dan semuanya dievaluasi tanpa eval/PHP:
 *
 *   {{ $nama }} / {{ nama }}            nilai, di-escape e() pada badan HTML
 *   {{ $obj->prop }} / {{ $obj.prop }}  properti / key bertingkat
 *   {{ $nama ?? 'bawaan' }}             nilai bawaan bila kosong/tidak ada
 *   {!! $nama !!}                       nilai mentah, tanpa escape
 *   {{-- komentar --}}                  dibuang
 *   @{{ teks }}                         ditulis harfiah sebagai {{ teks }}
 *   @if (KONDISI) ... @elseif (KONDISI) ... @else ... @endif
 *
 * KONDISI terbatas pada: `$a`, `! $a`, `empty($a)`, `! empty($a)`,
 * `isset($a)`, `! isset($a)`, dan `$a OP literal` dengan OP salah satu
 * `== != === !== < <= > >=` dan literal berupa angka, string berkutip,
 * true, false, atau null. Kondisi lain dianggap false.
 *
 * Ekspresi yang tidak dikenali (pemanggilan fungsi, operator lain, `@php`,
 * `@foreach`) dibiarkan sebagai teks harfiah: terlihat oleh admin di
 * pratinjau, dan tidak pernah dijalankan.
 *
 * Bentuk ini mencakup seluruh template bawaan (DefaultTemplateSeeder);
 * keluarannya identik dengan Blade untuk template-template itu, dikunci
 * oleh TemplateRendererTest.
 */
class TemplateRenderer
{
    /** Satu jalur variabel: $a, a, $a->b, $a.b, $a->b->c */
    private const PATH = '\$?[A-Za-z_][A-Za-z0-9_]*(?:(?:->|\.)[A-Za-z_][A-Za-z0-9_]*)*';

    /** Literal sederhana: angka, string berkutip tunggal/ganda, true/false/null. */
    private const LITERAL = '-?\d+(?:\.\d+)?|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|true|false|null';

    /**
     * Return ['subject' => ..., 'body' => ...] yang sudah ke-render.
     */
    public function render(NotificationTemplate $template, string $channel, array $data = []): array
    {
        $rawBody = $template->bodyFor($channel);
        if (! $rawBody) {
            throw new \InvalidArgumentException("Template '{$template->key}' tidak punya body untuk channel '{$channel}'");
        }

        $rawSubject = $template->subject;

        // Hanya `email-text` yang benar-benar teks polos. Kanal lain berakhir
        // sebagai HTML: surel dikirim sebagai htmlBody (juga saat jatuh ke
        // body_email_text), Telegram memakai parse_mode HTML, FCM membuang
        // tag. Dengan Blade semuanya dulu di-escape, jadi ini mempertahankan
        // keluaran lama.
        $html = $channel !== 'email-text';

        return [
            // Subjek adalah header surel, teks polos: tidak di-escape HTML.
            'subject' => $rawSubject ? $this->renderString($rawSubject, $data, false) : null,
            'body' => $this->renderString($rawBody, $data, $html),
        ];
    }

    /**
     * Render satu string template. Tidak pernah melempar exception dan tidak
     * pernah menjalankan kode apa pun dari isi template.
     */
    public function renderString(string $template, array $data = [], bool $html = true): string
    {
        // Komentar dibuang lebih dulu supaya direktif di dalamnya tidak ikut
        // diproses.
        $template = preg_replace('/\{\{--.*?--\}\}/s', '', $template) ?? $template;

        $tokens = $this->tokenize($template);
        $pos = 0;
        $tree = $this->parseBlock($tokens, $pos, []);

        // Struktur @if tidak seimbang: jangan menebak. Placeholder tetap
        // disubstitusi, direktif dibiarkan harfiah.
        if ($tree === null || $pos < count($tokens)) {
            return $this->substitute($template, $data, $html);
        }

        return $this->evaluate($tree, $data, $html);
    }

    // ─── Tokenizer & parser direktif ─────────────────────

    /**
     * Pecah template menjadi token teks dan direktif.
     *
     * @return array<int, array{type: string, value?: string, cond?: string}>
     */
    protected function tokenize(string $template): array
    {
        $tokens = [];
        $offset = 0;
        $len = strlen($template);
        $pattern = '/(?<![\w@])@(if|elseif|else|endif)\b/';

        while ($offset < $len && preg_match($pattern, $template, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $name = $m[1][0];
            $after = $start + strlen($m[0][0]);
            $cond = null;

            if ($name === 'if' || $name === 'elseif') {
                // Kondisi dalam kurung, boleh bersarang: (! empty($a)).
                $i = $after;
                while ($i < $len && ($template[$i] === ' ' || $template[$i] === "\t")) {
                    $i++;
                }
                if ($i >= $len || $template[$i] !== '(') {
                    // Bukan direktif yang sah: lanjutkan sebagai teks.
                    $tokens[] = ['type' => 'text', 'value' => substr($template, $offset, $after - $offset)];
                    $offset = $after;

                    continue;
                }
                $depth = 0;
                $j = $i;
                for (; $j < $len; $j++) {
                    if ($template[$j] === '(') {
                        $depth++;
                    } elseif ($template[$j] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                if ($depth !== 0) {
                    $tokens[] = ['type' => 'text', 'value' => substr($template, $offset)];
                    $offset = $len;

                    break;
                }
                $cond = substr($template, $i + 1, $j - $i - 1);
                $after = $j + 1;
            }

            if ($start > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($template, $offset, $start - $offset)];
            }

            // Sama seperti PHP setelah tag penutupnya: satu baris baru tepat sesudah
            // direktif ikut termakan, sehingga keluaran sama dengan Blade.
            if (substr($template, $after, 2) === "\r\n") {
                $after += 2;
            } elseif (($template[$after] ?? '') === "\n") {
                $after += 1;
            }

            $tokens[] = ['type' => $name, 'cond' => $cond];
            $offset = $after;
        }

        if ($offset < $len) {
            $tokens[] = ['type' => 'text', 'value' => substr($template, $offset)];
        }

        return $tokens;
    }

    /**
     * Susun token menjadi pohon. Berhenti pada token penutup yang diminta
     * pemanggil. Return null bila struktur tidak seimbang.
     */
    protected function parseBlock(array $tokens, int &$pos, array $stopAt): ?array
    {
        $nodes = [];

        while ($pos < count($tokens)) {
            $tok = $tokens[$pos];

            if (in_array($tok['type'], $stopAt, true)) {
                return $nodes;
            }

            if ($tok['type'] === 'text') {
                $nodes[] = $tok;
                $pos++;

                continue;
            }

            if ($tok['type'] !== 'if') {
                // elseif/else/endif tanpa @if pembuka.
                return null;
            }

            $branches = [];
            $cond = $tok['cond'];
            $pos++;

            while (true) {
                $body = $this->parseBlock($tokens, $pos, ['elseif', 'else', 'endif']);
                if ($body === null || $pos >= count($tokens)) {
                    return null;
                }
                $branches[] = ['cond' => $cond, 'body' => $body];

                $closing = $tokens[$pos];
                $pos++;

                if ($closing['type'] === 'endif') {
                    break;
                }
                if ($closing['type'] === 'elseif') {
                    $cond = $closing['cond'];

                    continue;
                }

                // @else: cabang terakhir, wajib ditutup @endif.
                $body = $this->parseBlock($tokens, $pos, ['endif']);
                if ($body === null || $pos >= count($tokens)) {
                    return null;
                }
                $branches[] = ['cond' => null, 'body' => $body];
                $pos++;

                break;
            }

            $nodes[] = ['type' => 'if', 'branches' => $branches];
        }

        return $stopAt === [] ? $nodes : null;
    }

    protected function evaluate(array $nodes, array $data, bool $html): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node['type'] === 'text') {
                $out .= $this->substitute($node['value'], $data, $html);

                continue;
            }

            foreach ($node['branches'] as $branch) {
                if ($branch['cond'] === null || $this->condition($branch['cond'], $data)) {
                    $out .= $this->evaluate($branch['body'], $data, $html);

                    break;
                }
            }
        }

        return $out;
    }

    // ─── Kondisi ─────────────────────────────────────────

    /**
     * Evaluasi kondisi terbatas. Tidak dikenali = false.
     */
    public function condition(string $cond, array $data): bool
    {
        $cond = trim($cond);
        $path = self::PATH;
        $literal = self::LITERAL;

        // Buang satu lapis kurung luar: (($a)) -> ($a) -> $a
        while (preg_match('/^\((.*)\)$/s', $cond, $m) && $this->balanced($m[1])) {
            $cond = trim($m[1]);
        }

        if (preg_match('/^!\s*(.+)$/s', $cond, $m)) {
            return ! $this->condition($m[1], $data);
        }

        if (preg_match('/^empty\s*\(\s*('.$path.')\s*\)$/', $cond, $m)) {
            return empty($this->resolve($m[1], $data));
        }

        if (preg_match('/^isset\s*\(\s*('.$path.')\s*\)$/', $cond, $m)) {
            return $this->resolve($m[1], $data) !== null;
        }

        if (preg_match('/^('.$path.')$/', $cond, $m)) {
            return (bool) $this->resolve($m[1], $data);
        }

        if (preg_match('/^('.$path.')\s*(===|!==|==|!=|<=|>=|<|>)\s*('.$literal.')$/', $cond, $m)) {
            $left = $this->resolve($m[1], $data);
            $right = $this->literal($m[3]);

            return match ($m[2]) {
                '===' => $left === $right,
                '!==' => $left !== $right,
                '==' => $left == $right,
                '!=' => $left != $right,
                '<=' => $left <= $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                '>' => $left > $right,
            };
        }

        return false;
    }

    private function balanced(string $s): bool
    {
        $depth = 0;
        foreach (str_split($s) as $c) {
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    // ─── Placeholder ─────────────────────────────────────

    /**
     * Ganti placeholder dalam satu potongan teks. Nilai yang disisipkan
     * TIDAK dipindai ulang, jadi isi data tidak dapat menyusupkan
     * placeholder atau direktif.
     */
    protected function substitute(string $text, array $data, bool $html): string
    {
        $path = self::PATH;
        $literal = self::LITERAL;
        $expr = '/^('.$path.')(?:\s*\?\?\s*('.$literal.'))?$/';

        return preg_replace_callback(
            '/(@?)\{\{\s*(.*?)\s*\}\}|\{!!\s*(.*?)\s*!!\}/s',
            function (array $m) use ($data, $html, $expr) {
                $isRaw = isset($m[3]) && $m[3] !== '' && ($m[2] ?? '') === '' && ($m[1] ?? '') === '';

                // @{{ x }} = tulis {{ x }} apa adanya, seperti Blade.
                if (! $isRaw && $m[1] === '@') {
                    return substr($m[0], 1);
                }

                $inner = $isRaw ? $m[3] : $m[2];

                if (! preg_match($expr, $inner, $e)) {
                    // Bukan placeholder yang dikenali: biarkan harfiah,
                    // jangan pernah dijalankan.
                    return $m[0];
                }

                $value = $this->resolve($e[1], $data);
                if (($value === null || $value === '') && isset($e[2]) && $e[2] !== '') {
                    $value = $this->literal($e[2]);
                }

                $string = $this->stringify($value);

                return ($html && ! $isRaw) ? e($string) : $string;
            },
            $text,
        ) ?? $text;
    }

    /**
     * Ambil nilai dari data berdasarkan jalur. Tidak ada = null.
     */
    protected function resolve(string $path, array $data): mixed
    {
        $parts = preg_split('/->|\./', ltrim($path, '$'));
        $value = $data;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->{$part})) {
                $value = $value->{$part};
            } else {
                return null;
            }
        }

        return $value;
    }

    protected function literal(string $raw): mixed
    {
        $lower = strtolower($raw);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        // String berkutip: buang kutip, buka escape sederhana.
        return stripcslashes(substr($raw, 1, -1));
    }

    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => '',
        };
    }
}
