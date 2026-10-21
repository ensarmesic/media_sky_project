<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\AdministrativeCaseItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Komentari (formatiranje, @spominjanje, prilozi, uređivanje) i linkovi. */
class CommentsAndLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Project $project;

    private AdministrativeCaseItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'username' => 'ensar', 'name' => 'Ensar Mešić']);
        $this->member = User::factory()->create(['role' => UserRole::TeamMember, 'username' => 'amir.k', 'name' => 'Amir Kovačević']);
        $this->project = Project::factory()->create(['responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->project->members()->attach([$this->manager->id => ['role' => 'manager'], $this->member->id => ['role' => 'member']]);
        $case = AdministrativeCase::create([
            'project_id' => $this->project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
        $this->item = $case->items()->create(['name' => 'Saglasnost EPBiH', 'responsible_user_id' => $this->member->id, 'created_by' => $this->manager->id]);
    }

    public function test_comment_renders_links_and_formatting_and_strips_html(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('body', "**Hitno** vidi https://example.com/zahtjev\n- jedan\n<script>alert(1)</script>")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSeeHtml('<strong>Hitno</strong>')
            ->assertSeeHtml('rel="noopener noreferrer" target="_blank"')
            ->assertSeeHtml('<li>jedan</li>')
            ->assertDontSeeHtml('<script>alert(1)</script>');

        $this->assertDatabaseHas('project_activities', ['event_type' => 'comment.created', 'subject_id' => $this->item->id]);
    }

    public function test_mention_notifies_member_and_responsible_gets_comment_notification(): void
    {
        $outsider = User::factory()->create(['username' => 'vanjski']);
        $colleague = User::factory()->create(['username' => 'mirza']);
        $this->project->members()->attach($colleague, ['role' => 'member']);
        Notification::fake();
        $this->actingAs($this->manager);

        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('body', '@mirza i @vanjski pogledajte, @ensar zna detalje')
            ->call('save');

        Notification::assertSentTo($colleague, WorkspaceNotification::class, fn ($n) => $n->kind === 'mentioned' && str_contains($n->url, '#komentar-'));
        Notification::assertNotSentTo($outsider, WorkspaceNotification::class);
        Notification::assertNotSentTo($this->manager, WorkspaceNotification::class);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn ($n) => $n->kind === 'task_commented');
    }

    public function test_attachment_is_stored_with_comment(): void
    {
        Storage::fake('local');
        $this->actingAs($this->member);

        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('body', 'Dopis u prilogu')
            ->set('files', [UploadedFile::fake()->create('dopis.pdf', 30)])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('dopis.pdf');

        $comment = Comment::firstOrFail();
        $this->assertSame('dopis.pdf', $comment->documents()->firstOrFail()->name);
        $this->assertSame($this->project->id, $comment->documents()->first()->project_id);
    }

    public function test_author_edits_and_deletes_own_comment_admin_deletes_any(): void
    {
        $comment = $this->item->comments()->create(['user_id' => $this->member->id, 'body' => 'Prvi tekst']);
        $this->actingAs($this->manager);
        $this->assertFalse($this->manager->can('update', $comment));
        $this->assertFalse($this->manager->can('delete', $comment));

        $this->actingAs($this->member);
        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->call('edit', $comment->id)->set('editBody', 'Ispravljen tekst')->call('update')
            ->assertSee('Ispravljen tekst')->assertSee('uređeno');
        $this->assertNotNull($comment->fresh()->edited_at);

        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($admin);
        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])->call('delete', $comment->id)->assertHasNoErrors();
        $this->assertSoftDeleted($comment);
    }

    public function test_viewer_cannot_comment(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->assertDontSee('Napišite komentar')
            ->set('body', 'Pokušaj')->call('save')->assertForbidden();
    }

    public function test_comment_mode_switch_shows_activity(): void
    {
        $this->item->comments()->create(['user_id' => $this->manager->id, 'body' => 'Komentar stavke']);
        app(AdministrativeCaseItemService::class)->changeStatus($this->item, CaseItemStatus::Submitted, $this->manager);
        $this->actingAs($this->manager);

        Livewire::test('comments', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->assertSee('Komentar stavke')->assertDontSee('promijenio/la status')
            ->set('mode', 'activity')->assertDontSee('Komentar stavke')->assertSee('Ensar M. je promijenio/la status iz Nije pokrenuto u Predato')
            ->set('mode', 'all')->assertSee('Komentar stavke')->assertSee('promijenio/la status');
    }

    public function test_links_are_validated_shown_by_domain_edited_and_deleted(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('links', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('form.url', 'javascript:alert(1)')->call('add')->assertHasErrors(['form.url'])
            ->set('form.url', 'https://www.epbih.ba/zahtjevi/123')->call('add')->assertHasNoErrors()
            ->assertSee('epbih.ba');

        $link = $this->item->links()->firstOrFail();
        $component->call('edit', $link->id)->set('editForm.title', 'Zahtjev na portalu')->call('update')->assertSee('Zahtjev na portalu');
        $component->call('delete', $link->id);
        $this->assertDatabaseMissing('links', ['id' => $link->id]);

        Livewire::test('links', ['ownerType' => 'project', 'ownerId' => $this->project->id])
            ->set('form.url', 'http://banovici.gov.ba')->call('add')->assertHasNoErrors()->assertSee('banovici.gov.ba');
    }

    public function test_viewer_cannot_manage_links(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('links', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->assertDontSee('+ Dodaj link')
            ->set('form.url', 'https://example.com')->call('add')->assertForbidden();
    }
}
