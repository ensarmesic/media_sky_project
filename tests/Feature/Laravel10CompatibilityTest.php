<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/** Zakrpe i zamjene koje aplikacija ima zbog Laravela 10 (staging server s PHP 8.1). */
class Laravel10CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_with_line_break_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)->test('pages::settings.users')
            ->set('name', 'Novi korisnik')->set('username', 'novi')
            ->set('email', "novi@example.com\r\nBcc: napadac@example.com")
            ->set('password', 'tajna-lozinka')->set('password_confirmation', 'tajna-lozinka')
            ->call('save')
            ->assertHasErrors(['email' => 'not_regex']);

        $this->assertDatabaseMissing('users', ['username' => 'novi']);
    }

    public function test_valid_email_still_passes(): void
    {
        $rules = ['email' => ['nullable', 'email', 'not_regex:/[\r\n]/']];

        $this->assertTrue(Validator::make(['email' => 'ensar@mediasky.ba'], $rules)->passes());
        $this->assertTrue(Validator::make(['email' => "ensar@mediasky.ba\n"], $rules)->fails());
    }

    public function test_user_initials(): void
    {
        $this->assertSame('EM', (new User(['name' => 'Ensar Mešić']))->initials());
        $this->assertSame('ŠK', (new User(['name' => 'šemsa  hadžić kurtović']))->initials());
        $this->assertSame('A', (new User(['name' => 'admin']))->initials());
    }

    public function test_password_rules_string(): void
    {
        $this->assertSame('minlength: 8;', password_rules_string());
    }

    /**
     * Livewire 4: wire:model.blur samo osvježi vrijednost u browseru, ne šalje je serveru, pa se
     * automatsko snimanje (updated…) ne pokrene. Za snimanje pri napuštanju polja treba wire:model.live.blur.
     */
    public function test_views_do_not_use_blur_binding_without_live(): void
    {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => preg_match('/wire:model\.(blur|change|enter)\b/', $file->getContents()))
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()->all();

        $this->assertSame([], $offenders);
    }

    /** MySQL odbija '' u koloni za datum; forme šalju prazna polja kao '' (greška na stagingu pri kreiranju projekta). */
    public function test_empty_strings_in_date_and_id_columns_are_saved_as_null(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['planned_end_date' => '', 'notes' => '', 'responsible_user_id' => $user->id, 'created_by' => $user->id]);

        $row = DB::table('projects')->where('id', $project->id)->first();
        $this->assertNull($row->planned_end_date);
        $this->assertSame('', $row->notes);

        $project->update(['planned_end_date' => '2026-12-31']);
        $project->update(['planned_end_date' => '']);
        $this->assertNull(DB::table('projects')->where('id', $project->id)->value('planned_end_date'));
    }

    /** Staging Apache ubacuje HTML iza svakog "<body>", i u JS; Livewire JS zato ide s izmijenjenim tekstom. */
    public function test_livewire_script_is_served_without_body_tag_text(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#src="([^"]*/livewire-[a-z0-9]+/livewire\.p1(?:\.min)?\.js[^"]*)"#', $html, $match));

        $script = (string) $this->get($match[1])->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->getContent();

        $this->assertStringNotContainsStringIgnoringCase('<body', $script);
        $this->assertStringContainsString('<'.chr(92).'u0062ody>', $script);
        $this->assertGreaterThan(100000, strlen($script));
    }
}
