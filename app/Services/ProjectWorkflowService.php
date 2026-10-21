<?php

namespace App\Services;

use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Enums\ProjectStatus;
use App\Models\AdministrativeCaseItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectWorkflowService
{
    /**
     * Promjena statusa projekta direktno iz detalja projekta.
     * Završavanje zahtijeva obrazloženje kada postoje neriješene obavezne stavke,
     * te je uvijek evidentirano u istoriji i obavještenjima.
     */
    public function changeStatus(Project $project, User $user, ProjectStatus $status, ?string $reason = null): Project
    {
        $unfinishedRequired = $this->unfinishedRequiredItems($project);

        if ($status === ProjectStatus::Completed && $unfinishedRequired->isNotEmpty() && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['completion_note' => 'Projekat ima neriješene obavezne stavke. Navedite obrazloženje za završavanje.']);
        }

        return DB::transaction(function () use ($project, $user, $status, $reason) {
            $old = $project->status;
            $updates = ['status' => $status];
            if ($status === ProjectStatus::Completed) {
                $updates['completed_at'] = $project->completed_at ?? now()->toDateString();
                $updates['completion_note'] = $reason;
            }
            if ($status !== ProjectStatus::Completed) {
                $updates['completed_at'] = null;
            }
            if ($status === ProjectStatus::Archived) {
                $updates['archived_at'] = now();
            }
            if ($old === ProjectStatus::Archived && $status !== ProjectStatus::Archived) {
                $updates['archived_at'] = null;
            }
            $project->update($updates);

            app(ProjectActivityService::class)->record($project, 'status_changed', 'Status projekta promijenjen', $user, $project, $old->label().' → '.$status->label().($reason !== null && $reason !== '' ? ' · '.$reason : null), ['field' => 'project_status', 'old' => $old->value, 'new' => $status->value]);

            if ($status === ProjectStatus::Completed) {
                app(NotificationDispatchService::class)->dispatch(
                    array_filter([$project->responsible, ...$project->members]),
                    NotificationKind::ProjectCompleted,
                    'Projekat je označen kao završen: '.$project->name,
                    route('projects.show', $project),
                );
            }

            return $project->refresh();
        });
    }

    /** Stavke koje blokiraju uredno završavanje projekta. */
    public function unfinishedRequiredItems(Project $project)
    {
        return AdministrativeCaseItem::query()
            ->where('is_active', true)
            ->where('is_required_for_closure', true)
            ->whereNotIn('status', CaseItemStatus::closedValues())
            ->whereIn('administrative_case_id', $project->administrativeCases()->select('id'))
            ->with('administrativeCase')
            ->get();
    }

    /** Sljedeći korak (kartica u desnoj koloni projekta). */
    public function setNextStep(Project $project, User $user, ?string $nextStep): Project
    {
        return $this->setTextField($project, $user, 'next_step', $nextStep, 'Promijenjen sljedeći korak');
    }

    /** Trenutno stanje (kartica u desnoj koloni projekta). */
    public function setCurrentState(Project $project, User $user, ?string $currentState): Project
    {
        return $this->setTextField($project, $user, 'current_state', $currentState, 'Promijenjeno trenutno stanje');
    }

    /** Dodavanje člana projekta (ili promjena uloge postojećeg člana). */
    public function addMember(Project $project, User $actor, User $member, string $role): void
    {
        $role = in_array($role, [Project::ROLE_MANAGER, Project::ROLE_MEMBER], true) ? $role : Project::ROLE_MEMBER;
        $exists = $project->members()->whereKey($member->id)->exists();
        $exists ? $project->members()->updateExistingPivot($member->id, ['role' => $role]) : $project->members()->attach($member->id, ['role' => $role]);

        app(ProjectActivityService::class)->record($project, $exists ? 'project.member_role' : 'project.member_added', ($exists ? 'Promijenjena uloga: ' : 'Dodan član projekta: ').$member->name, $actor, $project, null, ['member_id' => $member->id, 'role' => $role]);
        if (! $exists) {
            app(NotificationDispatchService::class)->dispatch([$member], NotificationKind::ProjectMember, 'Dodani ste na projekat: '.$project->name, route('projects.show', $project));
        }
    }

    public function removeMember(Project $project, User $actor, User $member): void
    {
        if ($project->responsible_user_id === $member->id) {
            throw ValidationException::withMessages(['member' => 'Odgovorna osoba projekta ne može biti uklonjena iz članova.']);
        }
        $project->members()->detach($member->id);
        app(ProjectActivityService::class)->record($project, 'project.member_removed', 'Uklonjen član projekta: '.$member->name, $actor, $project, null, ['member_id' => $member->id]);
    }

    private function setTextField(Project $project, User $user, string $field, ?string $value, string $title): Project
    {
        $value = filled($value) ? trim($value) : null;
        $old = $project->getAttribute($field);
        if ($old === $value) {
            return $project;
        }
        $project->update([$field => $value]);
        app(ProjectActivityService::class)->record($project, 'project.'.$field, $title, $user, $project, $value, ['field' => $field, 'old' => $old, 'new' => $value]);

        return $project->refresh();
    }
}
