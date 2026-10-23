<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Početna stranica nakon prijave.
     */
    public const HOME = '/projekti';

    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Provjera da aplikacija radi (kao /up u novijim verzijama Laravela).
            Route::get('up', fn () => response('OK'))->name('health');
        });
    }
}
