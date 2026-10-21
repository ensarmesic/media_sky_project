<?php

namespace App\Livewire\Forms;

use App\Enums\NotificationKind;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationDispatchService;
use App\Services\ProjectActivityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ProjectForm extends Form
{
    public ?Project $project = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $category_id = '';

    public string $location = '';

    public string $start_date = '';

    public string $planned_end_date = '';

    public string $responsible_user_id = '';

    public string $status = 'planned';

    public string $notes = '';

    /** @var array<int, int|string> */
    public array $member_ids = [];

    public function setProject(Project $project): void
    {
        $this->project = $project;
        $this->fill([
            'code' => $project->code,
            'name' => $project->name,
            'description' => $project->description ?? '',
            'category_id' => (string) $project->category_id,
            'location' => $project->location ?? '',
            'start_date' => $project->start_date->format('Y-m-d'),
            'planned_end_date' => $project->planned_end_date?->format('Y-m-d') ?? '',
            'responsible_user_id' => (string) $project->responsible_user_id,
            'status' => $project->status->value,
            'notes' => $project->notes ?? '',
            'member_ids' => $project->members()->pluck('users.id')->map(fn ($id) => (string) $id)->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('projects', 'code')->ignore($this->project)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'exists:project_categories,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'responsible_user_id' => ['required', 'exists:users,id'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'member_ids' => ['array'],
            'member_ids.*' => ['distinct', 'exists:users,id'],
        ];
    }

    public function save(): Project
    {
        $data = $this->validate();
        unset($data['member_ids']);

        return DB::transaction(function () use ($data) {
            $created = ! $this->project;
            if ($this->project) {
                $this->project->update($data);
                $project = $this->project;
            } else {
                $project = Project::create([...$data, 'created_by' => Auth::id()]);
            }
            $members = collect($this->member_ids)->mapWithKeys(fn ($id) => [$id => ['role' => 'member']]);
            $members->put((int) $data['responsible_user_id'], ['role' => 'manager']);
            $attached = $project->members()->sync($members->all())['attached'];
            app(NotificationDispatchService::class)->dispatch(User::whereKey($attached)->get(), NotificationKind::ProjectMember, 'Dodani ste na projekat: '.$project->name, route('projects.show', $project));
            app(ProjectActivityService::class)->record($project, $created ? 'project_created' : 'project_updated', $created ? 'Projekat kreiran' : 'Podaci projekta ažurirani', Auth::user(), $project);

            return $project->refresh();
        });
    }
}
