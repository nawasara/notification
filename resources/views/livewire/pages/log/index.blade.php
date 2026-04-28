<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Komunikasi', 'url' => '#'], ['label' => 'Notification Logs']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page.title>Notification Logs</x-nawasara-ui::page.title>
        <p class="text-sm text-gray-500 dark:text-neutral-400 mb-4">
            Audit setiap notifikasi yang Nawasara kirim — channel, recipient, status, error. Retry failed dari sini.
        </p>

        @livewire('nawasara-notification.log.section.table')
    </x-nawasara-ui::page.container>
</div>
