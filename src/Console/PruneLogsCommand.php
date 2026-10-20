<?php

namespace Nawasara\Notification\Console;

use Illuminate\Console\Command;
use Nawasara\Sync\Support\ChunkedPruner;

/**
 * Pembersihan `nawasara_notification_logs` dalam dua tahap.
 *
 * Tabel ini TERBESAR di basis data Nawasara (1 GB per Oktober 2026) padahal
 * barisnya hanya 59 ribu: setiap baris menyimpan badan email HTML lengkap,
 * termasuk layout. Jadi yang mahal adalah isinya, bukan jumlah barisnya.
 *
 *   1. Setelah strip_body_after_days: kolom `body` dikosongkan. Penerima,
 *      status, waktu kirim, galat, dan context TETAP ada, sehingga "siapa
 *      dikirimi apa dan kapan" masih bisa dijawab.
 *   2. Setelah delete_after_days: barisnya dihapus.
 *
 * Badan pesan lama juga menyimpan isi laporan warga dan alamat; menyimpannya
 * tanpa batas waktu bukan hanya soal disk.
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'nawasara-notification:prune
        {--dry-run : Hitung saja, tidak mengubah apa pun}';

    protected $description = 'Strip old notification bodies and delete very old notification logs';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $strip = (int) config('nawasara-notification.prune.strip_body_after_days', 30);
        $delete = (int) config('nawasara-notification.prune.delete_after_days', 180);
        $prefix = $dry ? '[dry-run] ' : '';

        if ($delete > 0) {
            $n = ChunkedPruner::deleteOlderThan('nawasara_notification_logs', 'created_at', now()->subDays($delete), dryRun: $dry);
            $this->line($prefix."Dihapus (> {$delete} hari): ".number_format($n));
        }

        if ($strip > 0) {
            $n = ChunkedPruner::nullColumnsOlderThan('nawasara_notification_logs', 'created_at', now()->subDays($strip), ['body'], dryRun: $dry);
            $this->line($prefix."Isi dikosongkan (> {$strip} hari): ".number_format($n));
        }

        // Ruang yang dibebaskan dipakai ulang InnoDB untuk baris berikutnya.
        // Mengembalikannya ke disk butuh OPTIMIZE TABLE manual, sekali saja.
        return self::SUCCESS;
    }
}
