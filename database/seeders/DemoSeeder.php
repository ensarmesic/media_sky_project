<?php

namespace Database\Seeders;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Comment;
use App\Models\Institution;
use App\Models\Link;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo podaci (3 projekta, predmeti, zadaci u svim fazama, komentari i linkovi).
 * Pokreće se samo ručno: php artisan db:seed --class=DemoSeeder
 * Ne mijenja postojeće zapise; ponovno pokretanje preskače već kreirane demo projekte.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DevelopmentSeeder::class);

        $users = collect([
            ['demo.voditelj', 'Amra Hodžić', UserRole::ProjectManager],
            ['demo.inzenjer', 'Haris Begić', UserRole::TeamMember],
            ['demo.pravnik', 'Lejla Mujić', UserRole::TeamMember],
            ['demo.pregled', 'Tarik Kovač', UserRole::Viewer],
        ])->mapWithKeys(fn (array $user) => [$user[0] => User::firstOrCreate(
            ['username' => $user[0]],
            ['name' => $user[1], 'email' => null, 'password' => 'password', 'role' => $user[2]],
        )]);
        $manager = $users['demo.voditelj'];
        $team = [$manager, $users['demo.inzenjer'], $users['demo.pravnik']];

        $institutions = collect(['Općina Banovići', 'Elektroprivreda BiH', 'JKP Vodovod i kanalizacija', 'BH Telecom', 'Zavod za prostorno uređenje'])
            ->map(fn (string $name) => Institution::firstOrCreate(['name' => $name]));
        $statuses = CaseStatus::pluck('id', 'system_key');
        $types = CaseType::pluck('id', 'name');

        $projects = [
            ['DEMO-001', 'FTTH mreža Lukavac', 'Telekomunikacije', 'Lukavac', ProjectStatus::InProgress, [
                ['Urbanistička saglasnost', 'Saglasnost', [
                    ['Idejni projekat trase', CaseItemStatus::Completed, CaseItemPriority::High, -20],
                    ['Saglasnost Elektroprivrede BiH', CaseItemStatus::Waiting, CaseItemPriority::High, -2],
                    ['Saglasnost Vodovoda', CaseItemStatus::InReview, CaseItemPriority::Medium, 3],
                    ['Katastarski plan', CaseItemStatus::InProgress, CaseItemPriority::Medium, 7],
                    ['Saglasnost BH Telecoma', CaseItemStatus::New, CaseItemPriority::Low, 14],
                ]],
                ['Odobrenje za građenje', 'Dozvola', [
                    ['Glavni projekat', CaseItemStatus::InProgress, CaseItemPriority::Urgent, 1],
                    ['Revizija projekta', CaseItemStatus::New, CaseItemPriority::High, 10],
                    ['Plaćanje naknade za uređenje zemljišta', CaseItemStatus::New, CaseItemPriority::Medium, null],
                ]],
            ]],
            ['DEMO-002', 'Rekonstrukcija vodovoda Centar', 'Infrastruktura', 'Banovići', ProjectStatus::InProgress, [
                ['Okolinska dozvola', 'Dozvola', [
                    ['Studija uticaja na okoliš', CaseItemStatus::Completed, CaseItemPriority::High, -30],
                    ['Javna rasprava', CaseItemStatus::Completed, CaseItemPriority::Medium, -10],
                    ['Rješenje o okolinskoj dozvoli', CaseItemStatus::Waiting, CaseItemPriority::High, 0],
                ]],
                ['Saglasnosti komunalnih preduzeća', 'Saglasnost', [
                    ['Saglasnost Toplane', CaseItemStatus::Cancelled, CaseItemPriority::Low, null],
                    ['Saglasnost Elektrodistribucije', CaseItemStatus::InProgress, CaseItemPriority::Medium, 5],
                    ['Saglasnost BH Telecoma', CaseItemStatus::InReview, CaseItemPriority::Medium, 2],
                ]],
            ]],
            ['DEMO-003', 'Poslovni objekat Industrijska zona', 'Građevinarstvo', 'Živinice', ProjectStatus::Planned, [
                ['Lokacijska informacija', 'Zahtjev', [
                    ['Zahtjev za lokacijsku informaciju', CaseItemStatus::Completed, CaseItemPriority::Medium, -5],
                    ['Geodetski snimak parcele', CaseItemStatus::InProgress, CaseItemPriority::High, -1],
                    ['Idejno rješenje', CaseItemStatus::New, CaseItemPriority::Medium, 21],
                ]],
            ]],
        ];

        foreach ($projects as [$code, $name, $category, $location, $status, $cases]) {
            if (Project::where('code', $code)->exists()) {
                continue;
            }

            $project = Project::create([
                'code' => $code, 'name' => $name, 'location' => $location, 'status' => $status,
                'category_id' => ProjectCategory::firstOrCreate(['name' => $category])->id,
                'start_date' => today()->subMonths(2), 'planned_end_date' => today()->addMonths(6),
                'responsible_user_id' => $manager->id, 'created_by' => $manager->id,
            ]);
            $project->members()->sync(collect($team)->mapWithKeys(fn (User $user) => [$user->id => ['role' => $user->is($manager) ? 'manager' : 'member']])->all());

            foreach ($cases as $casePosition => [$caseName, $typeName, $items]) {
                $case = AdministrativeCase::create([
                    'project_id' => $project->id, 'name' => $caseName, 'position' => $casePosition + 1,
                    'case_type_id' => $types[$typeName] ?? $types->first(), 'case_status_id' => $statuses['in_preparation'] ?? $statuses->first(),
                    'institution_id' => $institutions->random()->id, 'responsible_user_id' => $manager->id,
                    'started_at' => today()->subMonth(), 'created_by' => $manager->id,
                ]);

                foreach ($items as $position => [$itemName, $itemStatus, $priority, $dueInDays]) {
                    $assignee = $team[($casePosition + $position) % count($team)];
                    $item = AdministrativeCaseItem::create([
                        'administrative_case_id' => $case->id, 'name' => $itemName, 'status' => $itemStatus, 'priority' => $priority,
                        'due_date' => $dueInDays === null ? null : today()->addDays($dueInDays),
                        'responsible_user_id' => $assignee->id, 'position' => $position + 1, 'created_by' => $manager->id,
                        'description' => $position === 0 ? "Dokumentacija se nalazi na zajedničkom disku.\nKontakt osoba u instituciji: šalter 3." : null,
                    ]);

                    if ($position < 2) {
                        Comment::create(['commentable_type' => $item->getMorphClass(), 'commentable_id' => $item->id, 'user_id' => $manager->id, 'body' => 'Molim provjeru do kraja sedmice. Detalji na https://example.com/projekti/'.$code]);
                        Comment::create(['commentable_type' => $item->getMorphClass(), 'commentable_id' => $item->id, 'user_id' => $assignee->id, 'body' => '**Urađeno** djelimično — čekamo potpis odgovorne osobe.']);
                    }
                    if ($position === 1) {
                        Link::create(['linkable_type' => $item->getMorphClass(), 'linkable_id' => $item->id, 'user_id' => $manager->id, 'title' => 'Zahtjev na e-portalu', 'url' => 'https://example.com/e-portal/zahtjev/'.$item->id]);
                    }
                }

                Link::create(['linkable_type' => $case->getMorphClass(), 'linkable_id' => $case->id, 'user_id' => $manager->id, 'title' => null, 'url' => 'https://www.fbihvlada.gov.ba']);
                Comment::create(['commentable_type' => $case->getMorphClass(), 'commentable_id' => $case->id, 'user_id' => $manager->id, 'body' => 'Predmet otvoren, rokovi usaglašeni s investitorom.']);
            }
        }
    }
}
