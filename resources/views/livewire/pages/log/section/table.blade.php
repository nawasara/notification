<div>
    {{-- Status counts --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
        @php
            $statusCards = [
                'queued' => ['label' => 'Queued', 'color' => 'blue'],
                'sending' => ['label' => 'Sending', 'color' => 'blue'],
                'sent' => ['label' => 'Sent', 'color' => 'green'],
                'delivered' => ['label' => 'Delivered', 'color' => 'green'],
                'failed' => ['label' => 'Failed', 'color' => 'red'],
                'bounced' => ['label' => 'Bounced', 'color' => 'red'],
            ];
            $colorMap = [
                'blue' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400 border-blue-200 dark:border-blue-800',
                'green' => 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400 border-green-200 dark:border-green-800',
                'red' => 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400 border-red-200 dark:border-red-800',
            ];
        @endphp
        @foreach ($statusCards as $key => $cfg)
            <button type="button" wire:click="$set('statusFilter', '{{ $statusFilter === $key ? '' : $key }}')"
                class="text-left rounded-xl border p-3 transition {{ $statusFilter === $key ? 'ring-2 ring-blue-200 dark:ring-blue-900 ' : '' }} {{ $colorMap[$cfg['color']] }}">
                <div class="text-xs font-medium uppercase">{{ $cfg['label'] }}</div>
                <div class="text-2xl font-bold mt-1">{{ $this->statusCounts[$key] ?? 0 }}</div>
            </button>
        @endforeach
    </div>

    <x-nawasara-ui::filter-bar searchPlaceholder="Cari recipient, subject, template key..." searchModel="search">
        <x-nawasara-ui::filter-dropdown label="Channel" model="channelFilter"
            :items="['all' => 'Semua Channel', 'email' => 'Email']" />

        <x-slot:chips>
            @if ($statusFilter)
                <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
            @endif
            @if ($channelFilter)
                <x-nawasara-ui::filter-chip label="Channel: {{ $channelFilter }}" model="channelFilter" />
            @endif
            @if ($search)
                <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
            @endif
        </x-slot:chips>
    </x-nawasara-ui::filter-bar>

    <x-nawasara-ui::table
        :headers="['Waktu', 'Channel', 'Recipient', 'Template / Subject', 'Status', 'Attempts', '']"
        :title="'Logs ('.$this->logs->total().' total)'">
        <x-slot:table>
            @forelse ($this->logs as $log)
                <tr>
                    <td class="px-6 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-neutral-400 font-mono">
                        {{ $log->created_at->format('d M H:i:s') }}
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">{{ $log->channel }}</span>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm font-mono text-gray-700 dark:text-neutral-300 max-w-xs truncate">
                        {{ $log->recipient }}
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-700 dark:text-neutral-300 max-w-md">
                        @if ($log->template_key)
                            <div class="font-mono text-xs text-gray-500 dark:text-neutral-400">{{ $log->template_key }}</div>
                        @endif
                        <div class="truncate">{{ \Illuminate\Support\Str::limit($log->subject, 80) ?: '(no subject)' }}</div>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                        @php
                            $sColor = match($log->status) {
                                'queued', 'sending' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                'sent' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
                                'delivered' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                'failed', 'bounced' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                default => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $sColor }}">
                            {{ ucfirst($log->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-neutral-400 font-mono">
                        {{ $log->attempts }}
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap text-sm text-right">
                        <x-nawasara-ui::dropdown-menu-action :id="$log->id" :items="array_filter([
                            ['type' => 'click', 'label' => 'Detail', 'wire:click' => 'openDetail('.$log->id.')', 'modal' => 'notification-log-detail', 'icon' => 'lucide-eye', 'permission' => 'notification.log.view'],
                            in_array($log->status, ['failed', 'bounced'])
                                ? ['type' => 'click', 'label' => 'Retry', 'wire:click' => 'retry('.$log->id.')', 'icon' => 'lucide-refresh-cw', 'permission' => 'notification.log.retry']
                                : null,
                        ])" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-neutral-400">
                        Belum ada notification log.
                    </td>
                </tr>
            @endforelse
        </x-slot:table>

        <x-slot:footer>
            {{ $this->logs->links() }}
        </x-slot:footer>
    </x-nawasara-ui::table>

    {{-- Detail Modal --}}
    <x-nawasara-ui::modal id="notification-log-detail" maxWidth="3xl" :title="'Notification Log #'.($detailId ?? '')">
        @if ($this->detail)
            @php $log = $this->detail; @endphp
            <div class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-3">
                    <div><span class="text-gray-500">Channel:</span> <span class="font-medium">{{ $log->channel }}</span></div>
                    <div><span class="text-gray-500">Status:</span> <span class="font-medium">{{ ucfirst($log->status) }}</span></div>
                    <div class="col-span-2"><span class="text-gray-500">Recipient:</span> <span class="font-mono">{{ $log->recipient }}</span></div>
                    <div><span class="text-gray-500">Template:</span> <span class="font-mono text-xs">{{ $log->template_key ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Attempts:</span> {{ $log->attempts }}</div>
                    <div><span class="text-gray-500">Created:</span> {{ $log->created_at->format('Y-m-d H:i:s') }}</div>
                    <div><span class="text-gray-500">Sent at:</span> {{ $log->sent_at?->format('Y-m-d H:i:s') ?? '—' }}</div>
                    @if ($log->delivered_at)
                        <div><span class="text-gray-500">Delivered:</span> {{ $log->delivered_at->format('Y-m-d H:i:s') }}</div>
                    @endif
                    @if ($log->provider_message_id)
                        <div class="col-span-2"><span class="text-gray-500">Provider Message ID:</span> <span class="font-mono text-xs">{{ $log->provider_message_id }}</span></div>
                    @endif
                </div>

                @if ($log->error)
                    <div>
                        <h4 class="font-semibold text-red-700 dark:text-red-400 mb-1">Error</h4>
                        <pre class="text-xs bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 p-3 rounded overflow-x-auto whitespace-pre-wrap">{{ $log->error }}</pre>
                    </div>
                @endif

                @if ($log->subject)
                    <div>
                        <h4 class="font-semibold text-gray-700 dark:text-neutral-300 mb-1">Subject</h4>
                        <div class="px-3 py-2 rounded border border-gray-200 dark:border-neutral-700 bg-gray-50 dark:bg-neutral-900">{{ $log->subject }}</div>
                    </div>
                @endif

                @if ($log->body)
                    <div>
                        <h4 class="font-semibold text-gray-700 dark:text-neutral-300 mb-1">Body (rendered)</h4>
                        <div class="rounded border border-gray-200 dark:border-neutral-700 overflow-hidden">
                            <iframe srcdoc="{{ $log->body }}" class="w-full h-80 bg-white"></iframe>
                        </div>
                    </div>
                @endif

                @if (! empty($log->context))
                    <div>
                        <h4 class="font-semibold text-gray-700 dark:text-neutral-300 mb-1">Context</h4>
                        <pre class="text-xs bg-gray-50 dark:bg-neutral-900 p-3 rounded overflow-x-auto">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-nawasara-ui::button color="neutral" variant="outline" wire:click="closeDetail">Tutup</x-nawasara-ui::button>
        </x-slot:footer>
    </x-nawasara-ui::modal>
</div>
