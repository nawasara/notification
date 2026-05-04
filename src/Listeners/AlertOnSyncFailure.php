<?php

namespace Nawasara\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nawasara\Notification\Facades\Notify;
use Nawasara\Notification\Models\NotificationTemplate;
use Nawasara\Sync\Events\SyncJobFinalFailed;
use Nawasara\Sync\Models\SyncJob;

/**
 * Listen ke SyncJobFinalFailed dan kirim alert ke admin kalau sync untuk
 * (service+action) tertentu sudah gagal beruntun melebihi threshold.
 *
 * Loose coupling: pakai class_exists() guard supaya kalau nawasara/sync
 * tidak ke-install sama sekali, file ini tidak crash.
 *
 * Cooldown: setelah satu alert untuk (service+action) terkirim, listener
 * tidak akan kirim alert lagi sampai cooldown habis. Mencegah inbox flood
 * saat sync terus-menerus gagal.
 *
 * Queued listener supaya count query + send tidak block job worker yg
 * baru saja gagal.
 */
class AlertOnSyncFailure implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(SyncJobFinalFailed $event): void
    {
        $config = config('nawasara-notification.sync_failure_alert');

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $admins = $config['admin_emails'] ?? [];
        if (empty($admins)) {
            // Tidak ada penerima — log info supaya admin sadar config kosong
            Log::info('[notification] SyncJobFinalFailed received tapi admin_emails kosong, skip alert.', [
                'service' => $event->tracker->service,
                'action' => $event->tracker->action,
            ]);
            return;
        }

        $threshold = max(1, (int) ($config['consecutive_failures'] ?? 3));
        $lookbackHours = max(1, (int) ($config['lookback_hours'] ?? 6));

        $consecutive = $this->countConsecutiveFailures(
            $event->tracker->service,
            $event->tracker->action,
            $lookbackHours,
        );

        if ($consecutive < $threshold) {
            return;
        }

        // Cooldown guard
        $cooldownMin = max(1, (int) ($config['cooldown_minutes'] ?? 60));
        $cacheKey = $this->cacheKey($event->tracker->service, $event->tracker->action);
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addMinutes($cooldownMin));

        $templateKey = (string) ($config['template_key'] ?? 'sync.failed');

        // Sanity check — kalau template tidak ada, log + skip (jangan crash)
        if (! NotificationTemplate::where('key', $templateKey)->where('active', true)->exists()) {
            Log::warning('[notification] sync.failed template tidak ditemukan / inactive, alert skip.', [
                'template_key' => $templateKey,
            ]);
            return;
        }

        $data = [
            'service' => $event->tracker->service,
            'action' => $event->tracker->actionLabel(),
            'consecutive_failures' => $consecutive,
            'last_error' => (string) ($event->tracker->error ?? $event->exception->getMessage()),
            'sync_jobs_url' => url('admin/sync/jobs?service='.urlencode($event->tracker->service)),
        ];

        try {
            Notify::to(...$admins)
                ->template($templateKey)
                ->data($data)
                ->priority('high')
                ->context([
                    'source' => 'sync_failure_alert',
                    'sync_job_id' => $event->tracker->id,
                    'service' => $event->tracker->service,
                    'action' => $event->tracker->action,
                ])
                ->send();
        } catch (\Throwable $e) {
            // Jangan re-throw — listener gagal != sync job gagal lebih parah
            Log::error('[notification] AlertOnSyncFailure gagal kirim notif: '.$e->getMessage());
        }
    }

    /**
     * Hitung jumlah failed/conflict job berturut-turut untuk (service+action)
     * dalam window lookback_hours. Berhenti hitung saat ketemu success.
     */
    protected function countConsecutiveFailures(string $service, string $action, int $lookbackHours): int
    {
        $rows = SyncJob::query()
            ->where('service', $service)
            ->where('action', $action)
            ->where('created_at', '>=', now()->subHours($lookbackHours))
            ->whereIn('status', [
                SyncJob::STATUS_SUCCESS,
                SyncJob::STATUS_FAILED,
                SyncJob::STATUS_CONFLICT,
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['status']);

        $count = 0;
        foreach ($rows as $row) {
            if ($row->status === SyncJob::STATUS_SUCCESS) {
                break;
            }
            $count++;
        }

        return $count;
    }

    protected function cacheKey(string $service, string $action): string
    {
        return 'notification:sync_alert_cooldown:'.$service.':'.$action;
    }
}
