<?php

namespace App\Services;

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

            app(ProjectActivityService::class)->record($project, 'status_changed', 'Status projekta promijenjen', $user, $project, $old->label().' → '.$status->label().($reason !== null && $reason !== '' ? ' · '.$reason : null));

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
            ->whereIn('status', ['not_started', 'in_preparation', 'ready_to_submit', 'submitted', 'waiting_response', 'supplement_required'])
            ->whereIn('administrative_case_id', $project->administrativeCases()->select('id'))
            ->with('administrativeCase')
            ->get();
    }

    /** Brza izmjena sljedećeg koraka iz centralnog pregleda. */
    public function setNextStep(Project $project, User $user, ?string $nextStep): Project
    {
        $project->update(['next_step' => $nextStep]);
        if ($nextStep !== null && $nextStep !== '') {
            app(ProjectActivityService::class)->record($project, 'next_step_changed', 'Sljedeći korak definisan', $user, $project, $nextStep);
        }

        return $project->refresh();
    }
}
