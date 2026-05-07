<div>
    {{-- Page header — title + description left, time-window right. --}}
    <x-nawasara-ui::page-header
        title="Notification Logs"
        description="Audit setiap notifikasi yang Nawasara kirim — channel, recipient, status, error. Retry failed dari sini."
        :count="$this->logs->total().' total'">
        <x-nawasara-ui::time-window :window="$window" :from="$from" :to="$to" />
    </x-nawasara-ui::page-header>

    {{-- Status counts — clickable filter cards. Compact mode keeps the
         row of 6 from dominating the page; counts reflect the active
         time window (statusCounts() in PHP applies applyTimeWindow). --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 mb-4">
        @php
            // Icon dropped: compact mode shows a colored dot beside the
            // label instead, which carries enough state signal for a
            // 6-card filter row without the visual heft of an icon box.
            $statusCards = [
                'queued' => ['label' => 'Queued', 'color' => 'primary'],
                'sending' => ['label' => 'Sending', 'color' => 'info'],
                'sent' => ['label' => 'Sent', 'color' => 'success'],
                'delivered' => ['label' => 'Delivered', 'color' => 'success'],
                'failed' => ['label' => 'Failed', 'color' => 'danger'],
                'bounced' => ['label' => 'Bounced', 'color' => 'danger'],
            ];
        @endphp
        @foreach ($statusCards as $key => $cfg)
            <x-nawasara-ui::stat-card compact
                :label="$cfg['label']"
                :value="$this->statusCounts[$key] ?? 0"
                :color="$cfg['color']"
                :active="$statusFilter === $key"
                wire:click="setStatusFilter('{{ $key }}')" />
        @endforeach
    </div>

    @php
        $channelOptions = ['email' => 'Email'];
    @endphp

    {{-- Toolbar — Channel filter (multi-select) + search + export.
         Status is filtered via the stat-cards above (single-toggle UX),
         so it's NOT included in the filter-panel here. --}}
    <div class="space-y-2 mb-4">
        <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <x-nawasara-ui::filter-panel
                    label="Filter"
                    :state="['channelFilter' => $channelFilter]"
                    :multiple="['channelFilter']"
                    :labels="['channelFilter' => $channelOptions]"
                    :dimensions="['channelFilter' => 'Channel']">
                    <x-nawasara-ui::filter-group label="Channel" model="channelFilter" :items="$channelOptions" icon="lucide-radio" />
                </x-nawasara-ui::filter-panel>
            </div>

            <x-nawasara-ui::search-input model="search" placeholder="Cari recipient, subject, atau template key..." />

            <div class="flex items-center gap-2 shrink-0">
                <x-nawasara-ui::export-button
                    action="export"
                    tooltip="Ekspor notification logs (max 10rb baris)" />
            </div>
        </div>

        <div wire:ignore data-filter-chips></div>

        @if ($search || $statusFilter)
            <div class="flex flex-wrap items-center gap-2">
                @if ($statusFilter)
                    <x-nawasara-ui::filter-chip label="Status: {{ ucfirst($statusFilter) }}" model="statusFilter" />
                @endif
                @if ($search)
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                @endif
            </div>
        @endif
    </div>

    {{-- Sticky last column for the action menu so retry stays reachable. --}}
    <x-nawasara-ui::table
        stickyLast
        :headers="['Waktu', 'Channel', 'Recipient', 'Template / Subject', 'Status', 'Attempts', '']">
        <x-slot:table>
            @forelse ($this->logs as $log)
                <tr wire:key="notif-log-{{ $log->id }}">
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
                            // Map notification status to badge color tokens.
                            // queued/sending = blue (in-flight, non-final);
                            // sent = indigo (handed off but not yet acked);
                            // delivered = success (final positive);
                            // failed/bounced = danger (final negative).
                            $statusColor = match($log->status) {
                                'queued', 'sending' => 'blue',
                                'sent' => 'indigo',
                                'delivered' => 'success',
                                'failed', 'bounced' => 'danger',
                                default => 'neutral',
                            };
                        @endphp
                        <x-nawasara-ui::badge :color="$statusColor">
                            {{ ucfirst($log->status) }}
                        </x-nawasara-ui::badge>
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
                    <td colspan="7">
                        @if ($search || $statusFilter || ! empty($channelFilter) || $window !== '7d' || $from || $to)
                            <x-nawasara-ui::empty-state
                                icon="lucide-search-x"
                                title="Tidak ada log yang cocok"
                                description="Coba ubah periode/filter atau hapus search keyword."
                                variant="filter"
                                inline />
                        @else
                            <x-nawasara-ui::empty-state
                                icon="lucide-bell-off"
                                title="Belum ada notification log 7 hari terakhir"
                                description="Pilih periode lebih panjang atau Custom untuk melihat data lebih lama."
                                inline />
                        @endif
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
