<div>
    @php
        $statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];
        $priorityOptions = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];
    @endphp

    {{-- Page header — title + description left, primary action right.
         Templates aren't time-bound (config data, not events) so the
         time-window component is intentionally omitted. --}}
    <x-nawasara-ui::page-header
        title="Notification Templates"
        description="Template untuk semua notifikasi keluar Nawasara — email, WA (future), Telegram (future), in-app (future)."
        :count="$this->templates->total().' total'">
        @can('notification.template.create')
            <x-nawasara-ui::button color="primary" wire:click="$dispatch('openCreateTemplate')">
                <x-slot:icon><x-lucide-plus /></x-slot:icon>
                Tambah Template
            </x-nawasara-ui::button>
        @endcan
    </x-nawasara-ui::page-header>

    {{-- Toolbar — Status + Priority filters + search + export. --}}
    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['statusFilter' => $statusFilter, 'priorityFilter' => $priorityFilter]"
                    :multiple="['statusFilter', 'priorityFilter']"
                    :labels="['statusFilter' => $statusOptions, 'priorityFilter' => $priorityOptions]"
                    :dimensions="['statusFilter' => 'Status', 'priorityFilter' => 'Priority']">
                    <x-nawasara-ui::filter-group label="Status" model="statusFilter" :items="$statusOptions" icon="lucide-circle-check" />
                    <x-nawasara-ui::filter-group label="Priority" model="priorityFilter" :items="$priorityOptions" icon="lucide-flag" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari key, name, atau deskripsi..." />

            <div class="flex items-center gap-2 shrink-0">
                <x-nawasara-ui::export-button
                    action="export"
                    tooltip="Ekspor template list"
                    permission="notification.template.view" />
            </div>
        </div>

        <div wire:ignore data-filter-chips></div>

        @if ($search)
            <div class="flex flex-wrap items-center gap-2">
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            </div>
        @endif
    </div>

    <x-nawasara-ui::table
        stickyLast
        :headers="['Key', 'Name', 'Channels', 'Priority', 'Status', '']">
        <x-slot:table>
            @forelse ($this->templates as $tpl)
                <tr>
                    <td class="px-6 py-3 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-neutral-300">
                        {{ $tpl->key }}
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-neutral-200">
                        <div class="font-medium">{{ $tpl->name }}</div>
                        @if ($tpl->description)
                            <div class="text-xs text-gray-500 dark:text-neutral-400 truncate max-w-md">{{ \Illuminate\Support\Str::limit($tpl->description, 80) }}</div>
                        @endif
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($tpl->channels ?? [] as $ch)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">{{ $ch }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        @php
                            // Priority maps to severity-style badge colors.
                            // critical = danger; high = warning; low = neutral
                            // (deprioritized); normal/default = blue.
                            $priorityColor = match($tpl->priority) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'low' => 'neutral',
                                default => 'blue',
                            };
                        @endphp
                        <x-nawasara-ui::badge :color="$priorityColor">
                            {{ ucfirst($tpl->priority) }}
                        </x-nawasara-ui::badge>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        @if ($tpl->active)
                            <x-nawasara-ui::badge color="success">Active</x-nawasara-ui::badge>
                        @else
                            <x-nawasara-ui::badge color="neutral">Inactive</x-nawasara-ui::badge>
                        @endif
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm text-right">
                        <x-nawasara-ui::dropdown-menu-action :id="$tpl->id" :items="[
                            ['type' => 'click', 'label' => 'Preview', 'wire:click' => 'openPreview('.$tpl->id.')', 'modal' => 'notification-template-preview', 'icon' => 'lucide-eye', 'permission' => 'notification.template.view'],
                            ['type' => 'click', 'label' => 'Test Send', 'wire:click' => 'openTestSend('.$tpl->id.')', 'modal' => 'notification-template-test-send', 'icon' => 'lucide-send', 'permission' => 'notification.test.send'],
                            ['type' => 'click', 'label' => 'Edit', 'wire:click' => 'openEdit('.$tpl->id.')', 'modal' => 'notification-template-form', 'icon' => 'lucide-pencil', 'permission' => 'notification.template.update'],
                            ['type' => 'click', 'label' => $tpl->active ? 'Nonaktifkan' : 'Aktifkan', 'wire:click' => 'toggleActive('.$tpl->id.')', 'icon' => $tpl->active ? 'lucide-power-off' : 'lucide-power', 'permission' => 'notification.template.update'],
                            ['type' => 'click', 'label' => 'Hapus', 'wire:click' => 'delete('.$tpl->id.')', 'icon' => 'lucide-trash-2', 'confirm' => 'Hapus template ini?', 'permission' => 'notification.template.delete'],
                        ]" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        @if ($search || ! empty($statusFilter) || ! empty($priorityFilter))
                            <x-nawasara-ui::empty-state
                                icon="lucide-search-x"
                                title="Tidak ada template yang cocok"
                                description="Coba ubah filter atau hapus search keyword."
                                variant="filter"
                                inline />
                        @else
                            <x-nawasara-ui::empty-state
                                icon="lucide-mail-plus"
                                title="Belum ada template notifikasi"
                                description="Klik tombol Tambah Template di atas untuk mulai membuat template email."
                                inline />
                        @endif
                    </td>
                </tr>
            @endforelse
        </x-slot:table>

        <x-slot:footer>
            {{ $this->templates->links() }}
        </x-slot:footer>
    </x-nawasara-ui::table>

    {{-- Form Modal --}}
    <x-nawasara-ui::modal id="notification-template-form" maxWidth="2xl" :title="$editingId ? 'Edit Template' : 'Tambah Template'">
        <form wire:submit="save" id="notification-template-form-el" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <x-nawasara-ui::form.input label="Key (slug)" wire:model="formKey"
                    placeholder="e.g. ssl.expiry.warning" useError errorVariable="formKey" />
                <x-nawasara-ui::form.input label="Name" wire:model="formName"
                    placeholder="SSL Expiry Warning" useError errorVariable="formName" />
            </div>

            <div>
                <x-nawasara-ui::form.label value="Deskripsi (opsional)" />
                <x-nawasara-ui::form.textarea wire:model="formDescription" rows="2" placeholder="Untuk siapa, kapan dipakai..." />
            </div>

            <div>
                <x-nawasara-ui::form.label value="Subject" />
                <x-nawasara-ui::form.input wire:model="formSubject" placeholder="Welcome [user_name]" useError errorVariable="formSubject" />
                <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1">
                    Bisa pakai Blade syntax untuk variable: ketik <code class="font-mono">@{{ $name }}</code> akan di-replace saat render.
                </p>
            </div>

            <div>
                <x-nawasara-ui::form.label value="Body Email (HTML)" />
                <x-nawasara-ui::form.textarea wire:model="formBodyHtml" rows="8"
                    placeholder="<p>Halo [user_name], ...</p>" />
                <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1">
                    HTML + Blade syntax. Auto wrap di layout Nawasara.
                </p>
            </div>

            <div>
                <x-nawasara-ui::form.label value="Body Email (Plain Text — opsional)" />
                <x-nawasara-ui::form.textarea wire:model="formBodyText" rows="4"
                    placeholder="Plain text version untuk client yang tidak support HTML" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <x-nawasara-ui::form.label value="Priority" />
                    <x-nawasara-ui::form.select wire:model="formPriority" :placeholder="false">
                        <option value="low">Low</option>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </x-nawasara-ui::form.select>
                </div>
                <div class="flex items-end">
                    <x-nawasara-ui::form.checkbox label="Active" wire:model="formActive" />
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.label value="Channels" />
                <p class="text-xs text-gray-500 dark:text-neutral-400">MVP: hanya email aktif. WA + Telegram + In-app menyusul.</p>
                <div class="mt-2 flex gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="formChannels" value="email" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:bg-neutral-800 dark:border-neutral-600">
                        Email
                    </label>
                </div>
            </div>
        </form>

        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" @click="$dispatch('close-modal', 'notification-template-form')">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="notification-template-form-el" color="primary">Simpan</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Preview Modal --}}
    <x-nawasara-ui::modal id="notification-template-preview" maxWidth="3xl" title="Preview Template">
        @if ($previewId)
            <div class="space-y-4 text-sm">
                <div>
                    <x-nawasara-ui::form.label value="Variables (JSON)" />
                    <x-nawasara-ui::form.textarea wire:model="previewVars" rows="4"
                        class="font-mono text-xs"
                        placeholder='{"name": "Sample", "days": 7}' />
                    <x-nawasara-ui::button type="button" color="primary" variant="outline" size="sm" wire:click="renderPreview" class="mt-2">
                        <x-slot:icon><x-lucide-refresh-cw /></x-slot:icon>
                        Render
                    </x-nawasara-ui::button>
                </div>

                @if ($previewError)
                    <div class="p-3 rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 text-sm text-red-700 dark:text-red-300">
                        <x-lucide-alert-circle class="size-4 inline -mt-0.5" /> {{ $previewError }}
                    </div>
                @endif

                @if ($previewSubject !== null)
                    <div>
                        <h4 class="font-semibold text-gray-700 dark:text-neutral-300 mb-1">Subject</h4>
                        <div class="px-3 py-2 rounded border border-gray-200 dark:border-neutral-700 bg-gray-50 dark:bg-neutral-900 text-sm">
                            {{ $previewSubject ?: '(empty)' }}
                        </div>
                    </div>
                @endif

                @if ($previewBody !== null)
                    <div>
                        <h4 class="font-semibold text-gray-700 dark:text-neutral-300 mb-1">Body (rendered HTML)</h4>
                        <div class="rounded border border-gray-200 dark:border-neutral-700 overflow-hidden">
                            <iframe srcdoc="{{ $previewBody }}" class="w-full h-96 bg-white"></iframe>
                        </div>
                    </div>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closePreview">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>

    {{-- Test Send Modal --}}
    <x-nawasara-ui::modal id="notification-template-test-send" maxWidth="lg" title="Test Send Email">
        @if ($testSendId)
            <form wire:submit="doTestSend" id="notification-template-test-send-form" class="space-y-4">
                <p class="text-xs text-gray-500 dark:text-neutral-400">
                    Kirim sekali ke email tujuan untuk verify template + delivery jalan. Notifikasi tetap masuk ke
                    <a href="{{ url('nawasara-notification/logs') }}" wire:navigate class="text-emerald-700 dark:text-emerald-400 hover:underline font-medium">log</a>.
                </p>

                <x-nawasara-ui::form.input label="Recipient (email)" type="email"
                    wire:model="testSendRecipient" placeholder="anda@kominfo.go.id"
                    useError errorVariable="testSendRecipient" />

                <div>
                    <x-nawasara-ui::form.label value="Variables (JSON)" />
                    <x-nawasara-ui::form.textarea wire:model="testSendVars" rows="4"
                        class="font-mono text-xs"
                        placeholder='{"name": "Test User", "days": 7}' />
                    <p class="text-xs text-gray-500 dark:text-neutral-400 mt-1">
                        Sesuaikan dengan variable yang dipakai di template body/subject.
                    </p>
                </div>
            </form>
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeTestSend">Batal</x-nawasara-ui::button>
            <x-nawasara-ui::button type="submit" form="notification-template-test-send-form" color="primary">
                <x-slot:icon>
                    <x-lucide-send wire:loading.class="hidden" wire:target="doTestSend" />
                    <x-lucide-loader-2 wire:loading wire:target="doTestSend" class="animate-spin" />
                </x-slot:icon>
                Kirim Test
            </x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
