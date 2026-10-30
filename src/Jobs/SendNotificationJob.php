<?php

namespace Nawasara\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nawasara\Notification\Exceptions\PermanentNotificationFailure;
use Nawasara\Notification\Models\NotificationLog;
use Nawasara\Notification\Services\NotificationPayload;
use Nawasara\Notification\Services\NotificationService;

/**
 * Job ini dispatch via NotificationService::send(). Pull NotificationLog row,
 * resolve channel driver, panggil send(), update status.
 *
 * Tries + backoff dari config nawasara-notification.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public int $logId)
    {
    }

    public function tries(): int
    {
        return (int) config('nawasara-notification.tries', 3);
    }

    public function backoff(): array
    {
        return (array) config('nawasara-notification.backoff', [10, 30, 60]);
    }

    public function handle(NotificationService $service): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log) {
            return; // log dihapus, skip
        }

        // Idempotency guard — kalau sudah sent/delivered, skip
        if (in_array($log->status, [NotificationLog::STATUS_SENT, NotificationLog::STATUS_DELIVERED])) {
            return;
        }

        $log->markSending();

        try {
            $driver = $service->driver($log->channel);

            if (! $driver->isReady()) {
                throw new \RuntimeException("Channel '{$log->channel}' driver belum siap (config missing)");
            }

            $payload = new NotificationPayload(
                uuid: $log->uuid,
                channel: $log->channel,
                recipient: $log->recipient,
                subject: $log->subject,
                body: $log->body ?? '',
                userId: $log->user_id,
                templateId: $log->template_id,
                templateKey: $log->template_key,
                context: $log->context ?? [],
            );

            $providerId = $driver->send($payload);

            $log->markSent($providerId);
            $this->redactIfSensitive($log);
        } catch (PermanentNotificationFailure $e) {
            // Tidak akan pernah berhasil (mis. token push yang sudah mati).
            // Dicatat gagal lalu berhenti: mengulang tiga kali hanya menambah
            // panggilan ke penyedia yang pasti ditolak.
            $log->markFailed($e->getMessage());
            $this->redactIfSensitive($log);

            return;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());

            // Dijalankan sinkron (tanpa antrean) = tidak ada percobaan ulang,
            // jadi ini kesempatan terakhir menghapus isi rahasianya.
            if ($this->job === null) {
                $this->redactIfSensitive($log);
            }

            // Re-throw biar Laravel queue retry pakai backoff
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $log = NotificationLog::find($this->logId);
        if ($log) {
            $log->markFailed("Failed after {$log->attempts} attempts: ".$e->getMessage());
            $this->redactIfSensitive($log);
        }
    }

    /**
     * Hapus isi pesan dari log bila pengirim menandainya ->sensitive().
     *
     * Isi pesan disimpan di log karena job membacanya dari sana (dan karena
     * "kirim ulang" butuh isinya). Untuk pesan yang memuat rahasia, seperti
     * kode OTP reset MFA, menyimpannya berarti siapa pun yang bisa membuka
     * log dapat memakai kode itu selama masih berlaku. Isinya dihapus begitu
     * pengiriman selesai, berhasil maupun gagal final; subjek, penerima, dan
     * status tetap tercatat untuk audit.
     */
    protected function redactIfSensitive(NotificationLog $log): void
    {
        if (! ($log->context['sensitive'] ?? false)) {
            return;
        }

        $log->forceFill(['body' => NotificationLog::REDACTED_BODY])->save();
    }
}
