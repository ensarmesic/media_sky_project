<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baza i aplikacija rade u UTC, a korisniku se datumi i vremena prikazuju po Sarajevu. */
class LocalTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(array $attributes): AdministrativeCaseItem
    {
        $user = User::factory()->create();
        $case = AdministrativeCase::create([
            'project_id' => Project::factory()->create()->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $user->id, 'started_at' => now(), 'created_by' => $user->id,
        ]);

        return $case->items()->create(['name' => 'Stavka', 'status' => CaseItemStatus::InPreparation, 'created_by' => $user->id, ...$attributes]);
    }

    public function test_local_time_converts_utc_to_sarajevo(): void
    {
        $this->assertSame('21.10.2026. 14:38', local_time(Carbon::parse('2026-10-21 12:38', 'UTC'))->format('d.m.Y. H:i'));
        // Poslije prelaska na zimsko vrijeme (25.10.) razlika je jedan sat.
        $this->assertSame('26.10.2026. 13:38', local_time(Carbon::parse('2026-10-26 12:38', 'UTC'))->format('d.m.Y. H:i'));
        $this->assertNull(local_time(null));
    }

    public function test_local_today_switches_at_local_midnight(): void
    {
        // 22.10. u 23:30 UTC je već 23.10. u 01:30 po Sarajevu.
        Carbon::setTestNow(Carbon::parse('2026-10-22 23:30', 'UTC'));

        $this->assertSame('2026-10-23', local_today()->toDateString());
        $this->assertSame('2026-10-23 00:00:00', local_today()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', local_today()->getTimezone()->getName());
    }

    public function test_item_becomes_overdue_right_after_local_midnight(): void
    {
        $item = $this->item(['due_date' => '2026-10-22']);

        Carbon::setTestNow(Carbon::parse('2026-10-22 21:30', 'UTC')); // 23:30 po Sarajevu
        $this->assertFalse($item->fresh()->isOverdue());
        $this->assertSame(0, $item->fresh()->daysUntilDue());

        Carbon::setTestNow(Carbon::parse('2026-10-22 22:30', 'UTC')); // 00:30 sljedećeg dana po Sarajevu
        $this->assertTrue($item->fresh()->isOverdue());
        $this->assertSame(1, AdministrativeCaseItem::query()->overdue()->count());
    }

    public function test_dates_set_after_local_midnight_use_the_local_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 22:30', 'UTC')); // 23.10. 00:30 po Sarajevu

        $item = $this->item(['status' => CaseItemStatus::Submitted]);

        $this->assertSame('2026-10-23', $item->fresh()->submitted_at->toDateString());
    }

    public function test_scheduler_runs_on_sarajevo_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'items:send-deadline-reminders'));

        $this->assertSame('Europe/Sarajevo', $event->timezone);
        $this->assertSame('0 7 * * *', $event->expression);
    }

    public function test_passkey_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('passkey.login'));
        $this->assertFalse(Route::has('passkey.store'));
        $this->assertFalse(Route::has('well-known.passkeys'));
    }
}
