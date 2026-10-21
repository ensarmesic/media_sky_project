<?php

use App\Models\Project;
use App\Models\User;
use App\Support\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Puna historija projekta s filterom po tipu i korisniku. */
new #[Title('Historija projekta')] class extends Component {
    use WithPagination;

    public Project $project;

    /** '' | status | deadline | assignment | submission | response | document | link | comment | project | created */
    #[Url(as: 'tip', except: '')]
    public string $type = '';

    #[Url(as: 'korisnik', except: '')]
    public string $userId = '';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activities()
    {
        return $this->project->activities()
            ->with(['user:id,name,grammatical_gender', 'subject'])
            ->when($this->type !== '', fn (Builder $query) => ActivityPresenter::applyTypeFilter($query, $this->type))
            ->when($this->userId !== '', fn (Builder $query) => $query->where('user_id', $this->userId))
            ->latest()->latest('id')
            ->paginate(30);
    }

    #[Computed]
    public function users()
    {
        return User::whereIn('id', $this->project->activities()->select('user_id'))->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-5">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('projects.show', $project)" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Historija</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <flux:heading size="xl" level="1">Historija projekta</flux:heading>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="type" class="sm:max-w-60" aria-label="Vrsta promjene">
            <option value="">Sve promjene</option>
            @foreach(ActivityPresenter::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="userId" class="sm:max-w-60" aria-label="Korisnik">
            <option value="">Svi korisnici</option>
            @foreach($this->users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
        </flux:select>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:loading.delay.class="opacity-60">
        <ol class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->activities as $activity)
                <li class="flex gap-3 p-4" wire:key="activity-{{ $activity->id }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><flux:icon :name="$activity->icon()" variant="mini" class="size-4" /></span>
                    <div class="min-w-0 flex-1 text-sm">
                        <p>{{ ActivityPresenter::sentence($activity) }}</p>
                        @if($subject = ActivityPresenter::subjectLabel($activity))<p class="text-xs text-zinc-500">{{ $subject }}</p>@endif
                    </div>
                    <time class="shrink-0 text-xs text-zinc-500" datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->format('d.m.Y. H:i') }}">{{ $activity->created_at->format('d.m.Y.') }}</time>
                </li>
            @empty
                <li class="p-10 text-center text-sm text-zinc-500">Nema promjena za odabrane filtere.</li>
            @endforelse
        </ol>
    </section>
    {{ $this->activities->links() }}
</div>
