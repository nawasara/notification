<?php

namespace Nawasara\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());

            // Re-throw biar Laravel queue retry pakai backoff
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $log = NotificationLog::find($this->logId);
        if ($log) {
            $log->markFailed("Failed after {$log->attempts} attempts: ".$e->getMessage());
        }
    }
}
