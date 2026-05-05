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

class Table extends Component
{
    use HasBrowserToast;
    use WithPagination;

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $channelFilter = '';

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
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->channelFilter, fn ($q) => $q->where('channel', $this->channelFilter))
            ->when($this->search, fn ($q) => $q->where(function ($qq) {
                $qq->where('recipient', 'like', '%'.$this->search.'%')
                    ->orWhere('subject', 'like', '%'.$this->search.'%')
                    ->orWhere('template_key', 'like', '%'.$this->search.'%');
            }))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function statusCounts(): array
    {
        return NotificationLog::query()
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

    public function render()
    {
        return view('nawasara-notification::livewire.pages.log.section.table');
    }
}
