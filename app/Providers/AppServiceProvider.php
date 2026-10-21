<?php

namespace App\Providers;

use App\Services\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Livewire privremeni upload podrazumijevano dozvoljava 12 MB; usklađujemo s limitom dokumenata.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.config('projectflow.documents.max_size')]]);

        // Vrijeme zadnjeg obrađenog joba (kartica "Stanje sistema", app:health).
        Queue::after(fn (JobProcessed $event) => app(SystemHealth::class)->recordJobProcessed());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
