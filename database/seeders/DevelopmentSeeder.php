<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            ['name' => 'Administrator', 'email' => null, 'password' => 'password', 'email_verified_at' => null, 'role' => UserRole::Administrator],
        );

        foreach (['Telekomunikacije', 'Građevinarstvo', 'Infrastruktura', 'Softver', 'Interni projekti', 'Ostalo'] as $name) {
            ProjectCategory::firstOrCreate(['name' => $name]);
        }

        foreach ([
            ['in_preparation', 'U pripremi', 'zinc'], ['ready', 'Spremno za predaju', 'blue'], ['submitted', 'Predato', 'blue'], ['waiting', 'Čeka odgovor', 'amber'],
            ['supplement_required', 'Potrebna dopuna', 'red'], ['supplement_submitted', 'Dopuna dostavljena', 'amber'], ['resolved', 'Riješeno', 'green'], ['suspended', 'Obustavljeno', 'zinc'],
        ] as $position => [$key,$name,$color]) {
            CaseStatus::updateOrCreate(['system_key' => $key], ['name' => $name, 'color' => $color, 'position' => $position]);
        }
        foreach (['Zahtjev', 'Saglasnost', 'Dozvola', 'Mišljenje', 'Prijava', 'Drugo'] as $name) {
            CaseType::firstOrCreate(['name' => $name]);
        }
    }
}
