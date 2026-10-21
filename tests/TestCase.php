<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Zaštita prave baze: testovi (RefreshDatabase = migrate:fresh) smiju raditi samo nad SQLite bazom u memoriji.
     * Ako je konfiguracija keširana (php artisan config:cache / optimize), phpunit.xml se ignoriše i testovi bi
     * obrisali stvarne podatke — zato se tada odmah zaustavljaju, prije bilo kakve migracije.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        if ($app->configurationIsCached() || $database !== ':memory:') {
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
