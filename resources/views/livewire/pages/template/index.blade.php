<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Komunikasi', 'url' => '#'], ['label' => 'Templates']]" />
    </x-slot>

    {{-- Title + description + create button hoisted into the section
         component (page-header). Index is just a shell. --}}
    <x-nawasara-ui::page.container>
        @livewire('nawasara-notification.template.section.table')
    </x-nawasara-ui::page.container>
</div>
