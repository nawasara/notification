<?php

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

// Routes ditambah di Day 3 (Template UI + Log viewer)
Route::middleware(['web', 'auth'])->prefix('nawasara-notification')->group(function () {
    // Placeholder; diisi nanti.
});
