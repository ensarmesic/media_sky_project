<?php

namespace App\Providers;

use App\Http\Controllers\LivewireScriptController;
use App\Services\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use RuntimeException;

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

        // Livewire JS s izmijenjenim "<body" (vidi LivewireScriptController); nova putanja da browseri ne koriste stari keš.
        Livewire::setScriptRoute(fn () => Route::get(
            EndpointResolver::prefix().(config('app.debug') ? '/livewire.p1.js' : '/livewire.p1.min.js'),
            LivewireScriptController::class,
        ));

        // livewire/blaze (Flux) poziva $attributes->all(), koje Laravel 10 nema; isto što i getAttributes().
        if (! method_exists(ComponentAttributeBag::class, 'all')) {
            ComponentAttributeBag::macro('all', fn (): array => $this->getAttributes());
        }

        // Livewire privremeni upload podrazumijevano dozvoljava 12 MB; usklađujemo s limitom dokumenata.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.config('projectflow.documents.max_size')]]);

        // Vrijeme zadnjeg obrađenog joba (kartica "Stanje sistema", app:health).
        Queue::after(fn (JobProcessed $event) => app(SystemHealth::class)->recordJobProcessed());

        // Forme šalju prazna polja kao '' (npr. bez planiranog kraja). SQLite to prihvata, MySQL odbija
        // ("Incorrect date value: ''"), pa se prije snimanja '' u kolonama za datum, broj ili *_id pretvara u NULL.
        Event::listen('eloquent.saving: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }
            foreach ($model->getAttributes() as $key => $value) {
                // external_document_id i credential_id su tekstualni identifikatori, ne strani ključevi.
                $foreignKey = str_ends_with($key, '_id') && ! in_array($key, ['external_document_id', 'credential_id'], true);
                if ($value === '' && ($foreignKey || in_array($key, $model->getDates(), true)
                    || $model->hasCast($key, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime', 'timestamp', 'int', 'integer', 'decimal', 'float', 'double', 'real']))) {
                    $model->setAttribute($key, null);
                }
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Laravel 10 nema DB::prohibitDestructiveCommands(); ista zaštita: u produkciji ne daj komande koje brišu bazu.
        if (app()->isProduction()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event): void {
                if (in_array($event->command, ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset'], true)) {
                    throw new RuntimeException("Komanda {$event->command} je zabranjena u produkciji.");
                }
            });
        }

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
