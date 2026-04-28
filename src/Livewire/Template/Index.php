<?php

namespace Nawasara\Notification\Livewire\Template;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-notification::livewire.pages.template.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
