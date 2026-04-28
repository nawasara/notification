<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Notification\Livewire\Log\Index as LogIndex;
use Nawasara\Notification\Livewire\Template\Index as TemplateIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-notification')->group(function () {
    Route::get('templates', TemplateIndex::class)
        ->middleware(PermissionMiddleware::using('notification.template.view'))
        ->name('nawasara-notification.templates.index');

    Route::get('logs', LogIndex::class)
        ->middleware(PermissionMiddleware::using('notification.log.view'))
        ->name('nawasara-notification.logs.index');
});
