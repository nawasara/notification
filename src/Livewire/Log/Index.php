<?php

namespace Nawasara\Notification\Livewire\Log;

use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('nawasara-notification::livewire.pages.log.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
