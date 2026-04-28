<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Komunikasi', 'url' => '#'], ['label' => 'Templates']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <div class="flex items-center justify-between mb-4">
            <div>
                <x-nawasara-ui::page.title>Notification Templates</x-nawasara-ui::page.title>
                <p class="text-sm text-gray-500 dark:text-neutral-400">
                    Template untuk semua notifikasi keluar Nawasara — email, WA (future), Telegram (future), in-app (future).
                </p>
            </div>
            @can('notification.template.create')
                <x-nawasara-ui::button color="primary" wire:click="$dispatch('openCreateTemplate')">
                    <x-slot:icon><x-lucide-plus /></x-slot:icon>
                    Tambah Template
                </x-nawasara-ui::button>
            @endcan
        </div>

        @livewire('nawasara-notification.template.section.table')
    </x-nawasara-ui::page.container>
</div>
