<?php

namespace Database\Seeders;

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Comment;
use App\Models\Institution;
use App\Models\Link;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectCategory;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo podaci prema referentnom dizajnu (docs/dizajn-projekat.png): projekat
 * "FTTH Banovići" s postupcima i stavkama kao na slici, plus još 2 projekta.
 * Pokreće se samo ručno: php artisan db:seed --class=DemoSeeder
 * Ne mijenja postojeće zapise; ponovno pokretanje preskače već kreirane demo projekte.
 */
class DemoSeeder extends Seeder
{
    /** Najmanji ispravan PDF (za demo dokumente koji se mogu otvoriti). */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    /** @var array<string, User> */
    private array $users = [];

    public function run(): void
    {
        $this->call(DevelopmentSeeder::class);

        foreach ([
            'demo.ensar' => ['Ensar Mešić', UserRole::ProjectManager],
            'demo.amir.h' => ['Amir Hadžić', UserRole::TeamMember],
            'demo.amir.k' => ['Amir Kovačević', UserRole::TeamMember],
            'demo.mirza' => ['Mirza Smajić', UserRole::TeamMember],
            'demo.nedim' => ['Nedim Halilović', UserRole::TeamMember],
        ] as $username => [$name, $role]) {
            $this->users[$username] = User::firstOrCreate(['username' => $username], ['name' => $name, 'email' => null, 'password' => 'password', 'role' => $role]);
        }

        $this->ftthBanovici();
        $this->simpleProject('DEMO-002', 'Rekonstrukcija vodovoda Centar', 'Zamjena azbestnih cijevi u centru grada', 'Infrastruktura', 'Banovići', ProjectStatus::InProgress, [
            ['Okolinska dozvola', 'Federalno ministarstvo okoliša', 'shield-check', [
                ['Studija uticaja na okoliš', CaseItemStatus::Completed, -40, -45],
                ['Javna rasprava', CaseItemStatus::Completed, -20, -25],
                ['Rješenje o okolinskoj dozvoli', CaseItemStatus::WaitingResponse, 4, -16],
            ]],
            ['Saglasnosti komunalnih preduzeća', 'JKP Vodovod', 'building-office', [
                ['Saglasnost Elektrodistribucije', CaseItemStatus::InPreparation, 2, null],
                ['Saglasnost BH Telecoma', CaseItemStatus::Submitted, 9, -1],
                ['Saglasnost Toplane', CaseItemStatus::Cancelled, null, null],
            ]],
        ]);
        $this->simpleProject('DEMO-003', 'Poslovni objekat Industrijska zona', 'Hala za skladištenje s upravnom zgradom', 'Građevinarstvo', 'Živinice', ProjectStatus::Planned, [
            ['Lokacijska informacija', 'Općina Živinice', 'map-pin', [
                ['Zahtjev za lokacijsku informaciju', CaseItemStatus::NotStarted, 12, null],
                ['Geodetski snimak parcele', CaseItemStatus::NotStarted, 20, null],
            ]],
        ]);
    }

    /** Glavni demo projekat — postupci i stavke kao na referentnom dizajnu. */
    private function ftthBanovici(): void
    {
        if (Project::where('code', 'DEMO-001')->exists()) {
            return;
        }

        $ensar = $this->users['demo.ensar'];
        $project = $this->project('DEMO-001', 'FTTH Banovići', 'Podzemna optička mreža – GPON', 'Telekomunikacije', 'Banovići', ProjectStatus::InProgress, today()->subYear(), today()->addDays(70));
        $project->update([
            'progress' => 65,
            'current_state' => 'Čeka se odgovor EPBiH na zahtjev za saglasnost.',
            'next_step' => 'Pripremiti dopunu dokumentacije za Vodovod i Kanalizaciju.',
        ]);
        $project->members()->sync([
            $ensar->id => ['role' => Project::ROLE_MANAGER],
            $this->users['demo.amir.h']->id => ['role' => Project::ROLE_MEMBER],
            $this->users['demo.mirza']->id => ['role' => Project::ROLE_MEMBER],
            $this->users['demo.amir.k']->id => ['role' => Project::ROLE_MEMBER],
            $this->users['demo.nedim']->id => ['role' => Project::ROLE_MEMBER],
        ]);

        // [naziv, status, institucija/primalac, rok (dana od danas), predaja (dana od danas), odgovorna osoba]
        $urbanisticka = $this->case($project, 'Urbanistička saglasnost', 'Općina Banovići', 'document-text', 1, [
            ['Idejni projekat', CaseItemStatus::Completed, 'Interno', -12, -14, 'demo.ensar'],
            ['ZK izvadci i katastarska dok.', CaseItemStatus::Completed, 'Katastar', -10, -11, 'demo.amir.h'],
            ['Saglasnost EPBiH', CaseItemStatus::WaitingResponse, 'JP EPBiH', -2, -12, 'demo.amir.k'],
            ['Saglasnost BH Telecoma', CaseItemStatus::Submitted, 'BH Telecom', -2, -3, 'demo.mirza'],
            ['Saglasnost Vodovoda i kanalizacije', CaseItemStatus::InPreparation, 'JKP Vodovod', 3, null, 'demo.nedim'],
            ['Saglasnost Toplane', CaseItemStatus::NotStarted, 'Toplana', 5, null, 'demo.ensar'],
            ['Saglasnost TXTV-a', CaseItemStatus::NotStarted, 'TXTV', 8, null, 'demo.ensar'],
            ['Predaja kompletnog zahtjeva', CaseItemStatus::NotStarted, 'Općina Banovići', 13, null, 'demo.ensar'],
            ['Izdavanje urbanističke saglasnosti', CaseItemStatus::NotStarted, 'Općina Banovići', 69, null, 'demo.ensar'],
        ]);
        $this->case($project, 'Građevinska dozvola', 'Općina Banovići', 'document', 2, collect(['Glavni projekat', 'Revizija glavnog projekta', 'Dokaz o pravu građenja', 'Saglasnost vatrogasaca', 'Plaćanje naknada', 'Zahtjev za građevinsku dozvolu', 'Uviđaj na terenu', 'Rješenje o građevinskoj dozvoli'])
            ->map(fn (string $name, int $index) => [$name, CaseItemStatus::NotStarted, 'Općina Banovići', 30 + $index * 7, null, 'demo.ensar'])->all());
        $this->case($project, 'Saglasnost Elektroprivrede', 'JP EPBiH', 'bolt', 3, [
            ['Elaborat priključka', CaseItemStatus::Completed, 'JP EPBiH', -30, -32, 'demo.amir.k'],
            ['Projekat izmještanja', CaseItemStatus::Completed, 'JP EPBiH', -25, -27, 'demo.amir.k'],
            ['Uplata naknade', CaseItemStatus::Completed, 'JP EPBiH', -20, -21, 'demo.ensar'],
            ['Zahtjev za saglasnost', CaseItemStatus::Completed, 'JP EPBiH', -15, -16, 'demo.amir.k'],
            ['Rješenje o saglasnosti', CaseItemStatus::WaitingResponse, 'JP EPBiH', 6, -4, 'demo.amir.k'],
            ['Zapisnik o primopredaji', CaseItemStatus::NotStarted, 'JP EPBiH', 20, null, 'demo.amir.k'],
        ]);
        $this->case($project, 'Saglasnost upravitelja ceste', 'Direkcija cesta', 'map', 4, [
            ['Situacija trase uz cestu', CaseItemStatus::Completed, 'Direkcija cesta', -18, -19, 'demo.mirza'],
            ['Zahtjev za saglasnost', CaseItemStatus::Completed, 'Direkcija cesta', -9, -10, 'demo.mirza'],
            ['Projekat privremene signalizacije', CaseItemStatus::InPreparation, 'Direkcija cesta', 1, null, 'demo.mirza'],
            ['Dopuna zahtjeva', CaseItemStatus::Submitted, 'Direkcija cesta', 10, -1, 'demo.mirza'],
        ]);
        $this->case($project, 'Katastarska dokumentacija', 'Katastarski ured', 'map-pin', 5, [
            ['Kopija katastarskog plana', CaseItemStatus::Completed, 'Katastarski ured', -60, -62, 'demo.amir.h'],
            ['Posjedovni listovi', CaseItemStatus::Completed, 'Katastarski ured', -58, -60, 'demo.amir.h'],
            ['ZK izvadci', CaseItemStatus::Completed, 'Katastarski ured', -55, -58, 'demo.amir.h'],
            ['Geodetska podloga', CaseItemStatus::Completed, 'Katastarski ured', -40, -45, 'demo.amir.h'],
        ]);

        $ideal = $urbanisticka->items->firstWhere('name', 'Idejni projekat');
        $epbih = $urbanisticka->items->firstWhere('name', 'Saglasnost EPBiH');
        $this->document($project, $urbanisticka, 'Zahtjev za urbanističku saglasnost', 'outgoing', '03-23-456/26', 0, 'demo.ensar');
        $this->document($project, $ideal, 'Idejni projekat', 'internal', null, -14, 'demo.ensar');
        $this->document($project, $urbanisticka->items->firstWhere('name', 'ZK izvadci i katastarska dok.'), 'Katastarski izvadak', 'incoming', '04-12-789/26', -11, 'demo.amir.h');
        $this->document($project, $urbanisticka, 'Dopis Općine – dopuna', 'incoming', '02-15-321/26', -2, 'demo.ensar');

        Comment::create(['commentable_type' => $epbih->getMorphClass(), 'commentable_id' => $epbih->id, 'user_id' => $ensar->id, 'body' => '@demo.amir.k molim da provjeriš status zahtjeva na šalteru EPBiH.']);
        Comment::create(['commentable_type' => $epbih->getMorphClass(), 'commentable_id' => $epbih->id, 'user_id' => $this->users['demo.amir.k']->id, 'body' => 'Zvao sam — odgovor očekuju **do kraja sedmice**.']);
        Link::create(['linkable_type' => $project->getMorphClass(), 'linkable_id' => $project->id, 'user_id' => $ensar->id, 'title' => 'Prostorni plan Općine Banovići', 'url' => 'https://www.banovici.gov.ba']);

        // Posljednje aktivnosti kao na slici (s odgovarajućim datumima).
        foreach ([
            [-60, 'case.created', 'Kreiran postupak: Urbanistička saglasnost', $urbanisticka, 'demo.ensar'],
            [-14, 'task.status', 'Promijenjen status stavke: Idejni projekat', $ideal, 'demo.ensar'],
            [-11, 'document.uploaded', 'Dodana dokumentacija: Katastarski izvadak', $urbanisticka, 'demo.amir.h'],
            [-2, 'document.uploaded', 'Zaprimljen dopis – potrebna dopuna', $urbanisticka, 'demo.ensar'],
            [0, 'task.submitted', 'Predat zahtjev za urbanističku saglasnost', $urbanisticka, 'demo.ensar'],
        ] as [$days, $event, $title, $subject, $user]) {
            $activity = ProjectActivity::create(['project_id' => $project->id, 'user_id' => $this->users[$user]->id, 'event_type' => $event, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'title' => $title]);
            $activity->forceFill(['created_at' => now()->addDays($days), 'updated_at' => now()->addDays($days)])->save();
        }
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: CaseItemStatus, 2: ?int, 3: ?int}>}>  $cases */
    private function simpleProject(string $code, string $name, string $description, string $category, string $location, ProjectStatus $status, array $cases): void
    {
        if (Project::where('code', $code)->exists()) {
            return;
        }

        $project = $this->project($code, $name, $description, $category, $location, $status, today()->subMonths(3), today()->addMonths(8));
        $project->members()->sync([$this->users['demo.ensar']->id => ['role' => Project::ROLE_MANAGER], $this->users['demo.mirza']->id => ['role' => Project::ROLE_MEMBER]]);
        foreach ($cases as $position => [$caseName, $institution, $icon, $items]) {
            $this->case($project, $caseName, $institution, $icon, $position + 1, array_map(fn (array $item) => [$item[0], $item[1], $institution, $item[2], $item[3], 'demo.mirza'], $items));
        }
    }

    private function project(string $code, string $name, string $description, string $category, string $location, ProjectStatus $status, mixed $start, mixed $end): Project
    {
        return Project::create([
            'code' => $code, 'name' => $name, 'description' => $description, 'location' => $location, 'status' => $status,
            'category_id' => ProjectCategory::firstOrCreate(['name' => $category])->id,
            'start_date' => $start, 'planned_end_date' => $end,
            'responsible_user_id' => $this->users['demo.ensar']->id, 'created_by' => $this->users['demo.ensar']->id,
        ]);
    }

    /** @param  array<int, array{0: string, 1: CaseItemStatus, 2: string, 3: ?int, 4: ?int, 5: string}>  $items */
    private function case(Project $project, string $name, string $institution, string $icon, int $position, array $items): AdministrativeCase
    {
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => $name, 'icon' => $icon, 'position' => $position,
            'institution_id' => Institution::firstOrCreate(['name' => $institution])->id,
            'case_type_id' => CaseType::firstOrCreate(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::firstOrCreate(['system_key' => 'in_preparation'], ['name' => 'U pripremi'])->id,
            'responsible_user_id' => $this->users['demo.ensar']->id, 'started_at' => today()->subMonths(2), 'created_by' => $this->users['demo.ensar']->id,
        ]);

        foreach ($items as $index => [$itemName, $status, $recipient, $dueInDays, $submittedDaysAgo, $responsible]) {
            $case->items()->create([
                'name' => $itemName, 'status' => $status, 'position' => $index + 1,
                'recipient' => $recipient,
                'due_date' => $dueInDays === null ? null : today()->addDays($dueInDays),
                'submitted_at' => $submittedDaysAgo === null ? null : today()->addDays($submittedDaysAgo),
                'response_at' => $status === CaseItemStatus::Completed && $submittedDaysAgo !== null ? today()->addDays($submittedDaysAgo + 2)->min(today()) : null,
                'responsible_user_id' => $this->users[$responsible]->id,
                'created_by' => $this->users['demo.ensar']->id,
            ]);
        }

        return $case->load('items');
    }

    private function document(Project $project, mixed $owner, string $name, string $direction, ?string $protocol, int $days, string $user): void
    {
        $path = 'project-documents/'.$project->id.'/demo-'.str()->slug($name).'.pdf';
        Storage::disk(ProjectDocument::DISK)->put($path, self::PDF);

        ProjectDocument::create([
            'project_id' => $project->id, 'documentable_type' => $owner->getMorphClass(), 'documentable_id' => $owner->id,
            'uploaded_by' => $this->users[$user]->id, 'name' => $name, 'original_name' => str()->slug($name).'.pdf',
            'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen(self::PDF),
            'direction' => $direction, 'protocol_number' => $protocol, 'document_date' => today()->addDays($days), 'version' => 1,
        ])->forceFill(['created_at' => now()->addDays($days)])->save();
    }
}
