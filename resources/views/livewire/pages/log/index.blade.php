<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Komunikasi', 'url' => '#'], ['label' => 'Notification Logs']]" />
    </x-slot>

    {{-- Title + description hoisted into the section component so they
         share a row with the time-window selector (which lives in the
         component's reactive state). Index is just a shell: breadcrumb +
         container + section. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-notification.log.section.table')
    </x-nawasara-ui::page.container>
</div>
