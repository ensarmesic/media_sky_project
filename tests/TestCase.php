<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Zaštita prave baze: testovi (RefreshDatabase = migrate:fresh) smiju raditi samo nad SQLite bazom u memoriji
     * ili nad testnom bazom "*_testing" (npr. MySQL: DB_CONNECTION=mysql DB_DATABASE=mediasky_testing).
     * Ako je konfiguracija keširana (php artisan config:cache / optimize), phpunit.xml se ignoriše i testovi bi
     * obrisali stvarne podatke — zato se tada odmah zaustavljaju, prije bilo kakve migracije.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->configurationIsCached()) {
            throw new RuntimeException(
                'Testovi su zaustavljeni da ne obrišu pravu bazu (konfiguracija je keširana). '
                .'Pokreni "php artisan config:clear" pa ponovo testove.'
            );
        }

        $app->make(Kernel::class)->bootstrap();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        // Dozvoljeno: SQLite u memoriji ili posebna testna baza čije ime završava na "_testing" (npr. MySQL).
        if ($database !== ':memory:' && ! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Testovi su zaustavljeni da ne obrišu pravu bazu ({$connection}: {$database}). "
                .'Pokreni "php artisan config:clear" pa ponovo testove.'
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
