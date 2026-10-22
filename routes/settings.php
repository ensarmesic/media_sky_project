<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/categories', 'pages::settings.categories')->name('categories.index');
    Route::livewire('settings/case-catalogs', 'pages::settings.case-catalogs')->name('case-catalogs.index');
    Route::livewire('settings/users', 'pages::settings.users')->name('users.index');
    Route::livewire('settings/obavijesti', 'pages::settings.notifications')->name('notification-settings.edit');
    Route::livewire('settings/stanje-sistema', 'pages::settings.system-health')->name('system-health.index');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
