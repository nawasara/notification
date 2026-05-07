<?php

namespace Nawasara\Notification\Livewire\Log\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Notification\Jobs\SendNotificationJob;
use Nawasara\Notification\Models\NotificationLog;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Ui\Livewire\Concerns\HasExport;
use Nawasara\Ui\Livewire\Concerns\HasTimeWindow;

class Table extends Component
{
    use HasBrowserToast;
    use HasExport;
    use HasTimeWindow;
    use WithPagination;

    /**
     * Single status filter — bound to the clickable stat-cards above the
     * table. Stays scalar (not array) because the cards are single-toggle
     * UI: clicking the active card un-filters.
     */
    #[Url(except: '')]
    public string $statusFilter = '';

    /**
     * Multi-select channel filter (filter-panel array semantics).
     * Empty array == no filter. Channel set is small (email today,
     * sms/whatsapp/push later) so it benefits from multi-select.
     *
     * @var array<int, string>
     */
    #[Url]
    public array $channelFilter = [];

    public string $search = '';

    public ?int $detailId = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedChannelFilter(): void { $this->resetPage(); }

    /**
     * Toggle behavior for clickable status stat-card: clicking the active card
     * un-filters; clicking another switches to it.
     */
    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return NotificationLog::query()
            ->tap(fn ($q) => $this->applyTimeWindow($q, 'created_at'))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when(! empty($this->channelFilter), fn ($q) => $q->whereIn('channel', $this->channelFilter))
            ->when($this->search, fn ($q) => $q->where(function ($qq) {
                $qq->where('recipient', 'like', '%'.$this->search.'%')
                    ->orWhere('subject', 'like', '%'.$this->search.'%')
                    ->orWhere('template_key', 'like', '%'.$this->search.'%');
            }))
            ->latest()
            ->paginate(25);
    }

    /**
     * Counts must reflect the active time window — a user looking at
     * "today" shouldn't see all-time numbers and panic.
     */
    #[Computed]
    public function statusCounts(): array
    {
        return NotificationLog::query()
            ->tap(fn ($q) => $this->applyTimeWindow($q, 'created_at'))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
    }

    public function openDetail(int $id): void
    {
        Gate::authorize('notification.log.view');
        $this->detailId = $id;
        $this->dispatch('modal-open:notification-log-detail');
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
        $this->dispatch('modal-close:notification-log-detail');
    }

    #[Computed]
    public function detail(): ?NotificationLog
    {
        return $this->detailId ? NotificationLog::with(['template'])->find($this->detailId) : null;
    }

    public function retry(int $id): void
    {
        Gate::authorize('notification.log.retry');

        $log = NotificationLog::find($id);
        if (! $log) {
            return;
        }

        if (! in_array($log->status, [NotificationLog::STATUS_FAILED, NotificationLog::STATUS_BOUNCED])) {
            $this->toastError('Hanya log status failed/bounced yang bisa di-retry.');
            return;
        }

        // Reset status + dispatch ulang
        $log->update([
            'status' => NotificationLog::STATUS_QUEUED,
            'error' => null,
        ]);

        SendNotificationJob::dispatch($log->id)
            ->onQueue(config('nawasara-notification.queue', 'notifications'));

        $this->toastSuccess("Notification #{$log->id} di-retry — cek status setelah beberapa detik.");
    }

    /**
     * Export filename base — timestamp + extension appended by HasExport.
     */
    protected function exportFilename(): string
    {
        return 'notification-logs';
    }

    /**
     * Export FULL log dataset (capped) per spec. Body is omitted because
     * rendered HTML emails balloon xlsx file size; the in-app Detail
     * modal is the right place for body inspection.
     */
    protected function exportData(): iterable
    {
        return NotificationLog::query()
            ->latest()
            ->limit(10000)
            ->get()
            ->map(fn (NotificationLog $log) => [
                'ID' => $log->id,
                'Created' => optional($log->created_at)->format('Y-m-d H:i:s'),
                'Channel' => $log->channel,
                'Recipient' => $log->recipient,
                'Template Key' => $log->template_key,
                'Subject' => $log->subject,
                'Status' => $log->status,
                'Attempts' => $log->attempts,
                'Sent At' => optional($log->sent_at)->format('Y-m-d H:i:s'),
                'Delivered At' => optional($log->delivered_at)->format('Y-m-d H:i:s'),
                'Provider Message ID' => $log->provider_message_id,
                'Error' => $log->error,
            ]);
    }

    public function render()
    {
        return view('nawasara-notification::livewire.pages.log.section.table');
    }
}
