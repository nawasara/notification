<?php

namespace Nawasara\Notification\Livewire\Template\Section;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\Notification\Facades\Notify;
use Nawasara\Notification\Models\NotificationTemplate;
use Nawasara\Notification\Services\TemplateRenderer;
use Nawasara\Ui\Livewire\Concerns\HasBrowserToast;
use Nawasara\Ui\Livewire\Concerns\HasExport;

class Table extends Component
{
    use HasBrowserToast;
    use HasExport;
    use WithPagination;

    public string $search = '';

    /**
     * Multi-select filters (filter-panel array semantics).
     * Empty array == no filter. Statuses are 'active' / 'inactive';
     * priorities are 'low' / 'normal' / 'high' / 'critical'.
     *
     * @var array<int, string>
     */
    public array $statusFilter = [];

    /** @var array<int, string> */
    public array $priorityFilter = [];

    // Form modal state
    public ?int $editingId = null;
    public string $formKey = '';
    public string $formName = '';
    public string $formDescription = '';
    public string $formSubject = '';
    public string $formBodyHtml = '';
    public string $formBodyText = '';
    public array $formChannels = ['email'];
    public string $formPriority = 'normal';
    public bool $formActive = true;

    // Preview state
    public ?int $previewId = null;
    public string $previewVars = ''; // JSON input
    public ?string $previewSubject = null;
    public ?string $previewBody = null;
    public ?string $previewError = null;

    // Test send state
    public ?int $testSendId = null;
    public string $testSendRecipient = '';
    public string $testSendVars = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedPriorityFilter(): void { $this->resetPage(); }

    #[Computed]
    public function templates()
    {
        return NotificationTemplate::query()
            ->when($this->search, fn ($q) => $q->where(function ($qq) {
                $qq->where('key', 'like', '%'.$this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%')
                    ->orWhere('description', 'like', '%'.$this->search.'%');
            }))
            ->when(! empty($this->statusFilter), function ($q) {
                // 'active' / 'inactive' translate to a boolean column.
                // Both values selected == no constraint (every row matches).
                $wantActive = in_array('active', $this->statusFilter, true);
                $wantInactive = in_array('inactive', $this->statusFilter, true);
                if ($wantActive && ! $wantInactive) {
                    $q->where('active', true);
                } elseif ($wantInactive && ! $wantActive) {
                    $q->where('active', false);
                }
            })
            ->when(! empty($this->priorityFilter), fn ($q) => $q->whereIn('priority', $this->priorityFilter))
            ->orderBy('key')
            ->paginate(25);
    }

    #[On('openCreateTemplate')]
    public function openCreate(): void
    {
        Gate::authorize('notification.template.create');
        $this->resetForm();
        $this->dispatch('modal-open:notification-template-form');
    }

    public function openEdit(int $id): void
    {
        Gate::authorize('notification.template.update');

        $tpl = NotificationTemplate::find($id);
        if (! $tpl) {
            return;
        }

        $this->editingId = $tpl->id;
        $this->formKey = $tpl->key;
        $this->formName = $tpl->name;
        $this->formDescription = (string) $tpl->description;
        $this->formSubject = (string) $tpl->subject;
        $this->formBodyHtml = (string) $tpl->body_email_html;
        $this->formBodyText = (string) $tpl->body_email_text;
        $this->formChannels = $tpl->channels ?: ['email'];
        $this->formPriority = $tpl->priority;
        $this->formActive = $tpl->active;

        $this->dispatch('modal-open:notification-template-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'notification.template.update' : 'notification.template.create');

        $rules = [
            'formKey' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/i'],
            'formName' => ['required', 'string', 'max:200'],
            'formSubject' => ['nullable', 'string', 'max:500'],
            'formBodyHtml' => ['nullable', 'string'],
            'formBodyText' => ['nullable', 'string'],
            'formChannels' => ['array', 'min:1'],
            'formPriority' => ['in:low,normal,high,critical'],
        ];

        $this->validate($rules, [
            'formKey.regex' => 'Key hanya boleh huruf, angka, titik, underscore, dash',
        ]);

        $payload = [
            'key' => $this->formKey,
            'name' => $this->formName,
            'description' => $this->formDescription ?: null,
            'subject' => $this->formSubject ?: null,
            'body_email_html' => $this->formBodyHtml ?: null,
            'body_email_text' => $this->formBodyText ?: null,
            'channels' => array_values(array_filter($this->formChannels ?: ['email'])),
            'priority' => $this->formPriority,
            'active' => $this->formActive,
        ];

        try {
            if ($this->editingId) {
                NotificationTemplate::where('id', $this->editingId)->update($payload);
                $this->toastSuccess("Template {$this->formKey} di-update.");
            } else {
                NotificationTemplate::create($payload);
                $this->toastSuccess("Template {$this->formKey} dibuat.");
            }
            $this->dispatch('modal-close:notification-template-form');
            $this->resetForm();
            unset($this->templates);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        Gate::authorize('notification.template.delete');

        try {
            NotificationTemplate::where('id', $id)->delete();
            $this->toastSuccess('Template dihapus.');
            unset($this->templates);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('notification.template.update');

        $tpl = NotificationTemplate::find($id);
        if (! $tpl) {
            return;
        }
        $tpl->update(['active' => ! $tpl->active]);
        $this->toastSuccess($tpl->active ? 'Template di-aktifkan' : 'Template di-nonaktifkan');
        unset($this->templates);
    }

    // ─── Preview ─────────────────────────────────────────

    public function openPreview(int $id): void
    {
        Gate::authorize('notification.template.view');

        $tpl = NotificationTemplate::find($id);
        if (! $tpl) {
            return;
        }

        $this->previewId = $id;

        // Pre-fill var hint dari schema kalau ada
        $hint = [];
        foreach (($tpl->variables ?? []) as $v) {
            $hint[$v['name'] ?? 'var'] = $v['type'] === 'integer' ? 0 : ($v['name'] ?? 'sample');
        }
        $this->previewVars = $hint ? json_encode($hint, JSON_PRETTY_PRINT) : "{\n    \"name\": \"Sample\"\n}";

        $this->renderPreview();
        $this->dispatch('modal-open:notification-template-preview');
    }

    public function renderPreview(): void
    {
        if (! $this->previewId) {
            return;
        }

        $tpl = NotificationTemplate::find($this->previewId);
        if (! $tpl) {
            return;
        }

        $vars = json_decode($this->previewVars ?: '{}', true);
        if (! is_array($vars)) {
            $this->previewError = 'Variables JSON invalid';
            $this->previewSubject = null;
            $this->previewBody = null;
            return;
        }

        try {
            $renderer = app(TemplateRenderer::class);
            $rendered = $renderer->render($tpl, 'email', $vars);
            $this->previewSubject = $rendered['subject'];
            $this->previewBody = $rendered['body'];
            $this->previewError = null;
        } catch (\Throwable $e) {
            $this->previewError = $e->getMessage();
            $this->previewSubject = null;
            $this->previewBody = null;
        }
    }

    public function closePreview(): void
    {
        $this->previewId = null;
        $this->previewSubject = null;
        $this->previewBody = null;
        $this->previewError = null;
        $this->dispatch('modal-close:notification-template-preview');
    }

    // ─── Test Send ───────────────────────────────────────

    public function openTestSend(int $id): void
    {
        Gate::authorize('notification.test.send');

        $tpl = NotificationTemplate::find($id);
        if (! $tpl) {
            return;
        }

        $this->testSendId = $id;
        $this->testSendRecipient = (string) (auth()->user()?->email ?? '');

        // Pre-fill var hint dari schema kalau ada
        $hint = [];
        foreach (($tpl->variables ?? []) as $v) {
            $hint[$v['name'] ?? 'var'] = ($v['type'] ?? 'string') === 'integer' ? 0 : 'sample';
        }
        $this->testSendVars = $hint ? json_encode($hint, JSON_PRETTY_PRINT) : "{\n    \"name\": \"Test User\"\n}";

        $this->dispatch('modal-open:notification-template-test-send');
    }

    public function doTestSend(): void
    {
        Gate::authorize('notification.test.send');

        $this->validate([
            'testSendRecipient' => ['required', 'email', 'max:255'],
        ]);

        $tpl = NotificationTemplate::find($this->testSendId);
        if (! $tpl) {
            $this->toastError('Template not found.');
            return;
        }

        if (! $tpl->active) {
            $this->toastError('Template is inactive — activate it first or test on an active template.');
            return;
        }

        $vars = json_decode($this->testSendVars ?: '{}', true);
        if (! is_array($vars)) {
            $this->toastError('Variables JSON invalid — periksa format.');
            return;
        }

        try {
            $logs = Notify::to($this->testSendRecipient)
                ->template($tpl->key)
                ->data($vars)
                ->context(['source' => 'template-test-send', 'triggered_by_user_id' => auth()->id()])
                ->sync()
                ->send();

            $log = $logs[0] ?? null;
            if ($log && $log->refresh()->status === \Nawasara\Notification\Models\NotificationLog::STATUS_SENT) {
                $this->toastSuccess("Test email sent to {$this->testSendRecipient}. Check inbox + /nawasara-notification/logs.");
            } elseif ($log) {
                $this->toastError("Send returned status={$log->status}: ".($log->error ?? 'unknown'));
            } else {
                $this->toastError('No log created — channel mismatch?');
            }

            $this->closeTestSend();
        } catch (\Throwable $e) {
            $this->toastError('Test send failed: '.$e->getMessage());
        }
    }

    public function closeTestSend(): void
    {
        $this->testSendId = null;
        $this->testSendRecipient = '';
        $this->testSendVars = '';
        $this->dispatch('modal-close:notification-template-test-send');
    }

    // ─── Helpers ─────────────────────────────────────────

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->formKey = '';
        $this->formName = '';
        $this->formDescription = '';
        $this->formSubject = '';
        $this->formBodyHtml = '';
        $this->formBodyText = '';
        $this->formChannels = ['email'];
        $this->formPriority = 'normal';
        $this->formActive = true;
        $this->resetErrorBag();
    }

    /**
     * Export filename base — timestamp + extension appended by HasExport.
     */
    protected function exportFilename(): string
    {
        return 'notification-templates';
    }

    /**
     * Export ALL templates (no filter) per spec. Body HTML/text are
     * included verbatim because admins offboarding/auditing templates
     * typically want the source verbatim.
     */
    protected function exportData(): iterable
    {
        return NotificationTemplate::query()
            ->orderBy('key')
            ->get()
            ->map(fn (NotificationTemplate $tpl) => [
                'Key' => $tpl->key,
                'Name' => $tpl->name,
                'Description' => $tpl->description,
                'Subject' => $tpl->subject,
                'Body HTML' => $tpl->body_email_html,
                'Body Text' => $tpl->body_email_text,
                'Channels' => is_array($tpl->channels) ? implode(', ', $tpl->channels) : (string) $tpl->channels,
                'Priority' => $tpl->priority,
                'Active' => $tpl->active ? 'Yes' : 'No',
                'Created' => optional($tpl->created_at)->format('Y-m-d H:i:s'),
                'Updated' => optional($tpl->updated_at)->format('Y-m-d H:i:s'),
            ]);
    }

    public function render()
    {
        return view('nawasara-notification::livewire.pages.template.section.table');
    }
}
