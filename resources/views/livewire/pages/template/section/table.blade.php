<div>
    <x-nawasara-ui::filter-bar searchPlaceholder="Cari key, name, deskripsi..." searchModel="search">
        <x-nawasara-ui::filter-dropdown label="Status" model="statusFilter"
            :items="['all' => 'Semua Status', 'active' => 'Active', 'inactive' => 'Inactive']" />
        <x-nawasara-ui::filter-dropdown label="Priority" model="priorityFilter"
            :items="['all' => 'Semua Priority', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical']" />

        <x-slot:chips>
            @if ($statusFilter)
                <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
            @endif
            @if ($priorityFilter)
                <x-nawasara-ui::filter-chip label="Priority: {{ ucfirst($priorityFilter) }}" model="priorityFilter" />
            @endif
            @if ($search)
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            @endif
        </x-slot:chips>
    </x-nawasara-ui::filter-bar>

    <x-nawasara-ui::table
        :headers="['Key', 'Name', 'Channels', 'Priority', 'Status', '']"
        :title="'Templates ('.$this->templates->total().' total)'">
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
                            $pColor = match($tpl->priority) {
                                'critical' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                'high' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                                'low' => 'bg-gray-100 text-gray-600 dark:bg-neutral-700 dark:text-neutral-400',
                                default => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $pColor }}">
                            {{ ucfirst($tpl->priority) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        @if ($tpl->active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400">Active</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-neutral-700 dark:text-neutral-400">Inactive</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm text-right">
                        <x-nawasara-ui::dropdown-menu-action :id="$tpl->id" :items="[
                            ['type' => 'click', 'label' => 'Preview', 'wire:click' => 'openPreview('.$tpl->id.')', 'modal' => 'notification-template-preview', 'icon' => 'lucide-eye', 'permission' => 'notification.template.view'],
                            ['type' => 'click', 'label' => 'Edit', 'wire:click' => 'openEdit('.$tpl->id.')', 'modal' => 'notification-template-form', 'icon' => 'lucide-pencil', 'permission' => 'notification.template.update'],
                            ['type' => 'click', 'label' => $tpl->active ? 'Nonaktifkan' : 'Aktifkan', 'wire:click' => 'toggleActive('.$tpl->id.')', 'icon' => $tpl->active ? 'lucide-power-off' : 'lucide-power', 'permission' => 'notification.template.update'],
                            ['type' => 'click', 'label' => 'Hapus', 'wire:click' => 'delete('.$tpl->id.')', 'icon' => 'lucide-trash-2', 'confirm' => 'Hapus template ini?', 'permission' => 'notification.template.delete'],
                        ]" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                        Belum ada template. Klik <strong>Tambah Template</strong> untuk mulai.
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
                        <input type="checkbox" wire:model="formChannels" value="email" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:bg-neutral-800 dark:border-neutral-600">
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
</div>
