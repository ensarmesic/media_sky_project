<?php

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCaseItem;
use App\Models\Institution;
use App\Services\AdministrativeCaseItemService;
use App\Services\InstitutionOverview;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Detalj institucije: kontakt podaci i otvorene stavke iz projekata korisnika, grupisano. */
new #[Title('Institucija')] class extends Component {
    public Institution $institution;

    public bool $editing = false;

    /** @var array<string, string> */
    public array $contact = [];

    public function mount(Institution $institution): void
    {
        Gate::authorize('view', $institution);
        $this->institution = $institution;
    }

    /** @return array<string, Illuminate\Database\Eloquent\Collection<int, AdministrativeCaseItem>> */
    #[Computed]
    public function groups(): array
    {
        return app(InstitutionOverview::class)->groups($this->institution, auth()->user());
    }

    /** Projekti u kojima korisnik smije mijenjati stavke (jedna provjera po projektu, ne po stavci). */
    #[Computed]
    public function editableProjectIds(): array
    {
        $user = auth()->user();
        if ($user->role === UserRole::Viewer) {
            return [];
        }

        return collect($this->groups)->flatten()
            ->map(fn (AdministrativeCaseItem $item) => $item->administrativeCase->project)
            ->unique('id')
            ->filter(fn ($project) => $user->isAdministrator() || ($user->role === UserRole::ProjectManager && $user->can('update', $project)))
            ->pluck('id')->all();
    }

    #[Computed]
    public function waitingText(): string
    {
        return app(InstitutionOverview::class)->waitingListText($this->institution, $this->groups['waiting']);
    }

    public function canEditItem(AdministrativeCaseItem $item): bool
    {
        $user = auth()->user();

        return $user->role !== UserRole::Viewer
            && ($item->responsible_user_id === $user->id || in_array($item->administrativeCase->project_id, $this->editableProjectIds, true));
    }

    public function changeItemStatus(int $itemId, string $status, AdministrativeCaseItemService $service): void
    {
        $item = AdministrativeCaseItem::with('administrativeCase.project')->findOrFail($itemId);
        Gate::authorize('update', $item);
        validator(['status' => $status], ['status' => ['required', Rule::enum(CaseItemStatus::class)]])->validate();
        $service->changeStatus($item, CaseItemStatus::from($status), auth()->user());
        unset($this->groups, $this->waitingText);
    }

    public function startEditing(): void
    {
        Gate::authorize('update', $this->institution);
        $this->contact = collect($this->institution->only(['name', 'type', 'address', 'contact_person', 'phone', 'email', 'notes']))->map(fn ($value) => (string) $value)->all();
        $this->editing = true;
    }

    public function saveContact(): void
    {
        Gate::authorize('update', $this->institution);
        $data = $this->validate([
            'contact.name' => ['required', 'string', 'max:255', Rule::unique('institutions', 'name')->ignore($this->institution->id)],
            'contact.type' => ['nullable', 'string', 'max:100'],
            'contact.address' => ['nullable', 'string', 'max:255'],
            'contact.contact_person' => ['nullable', 'string', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'contact.name' => 'naziv', 'contact.type' => 'vrsta', 'contact.address' => 'adresa', 'contact.contact_person' => 'kontakt osoba',
            'contact.phone' => 'telefon', 'contact.email' => 'e-mail', 'contact.notes' => 'napomena',
        ])['contact'];
        $this->institution->update(array_map(fn ($value) => filled($value) ? trim((string) $value) : null, $data));
        $this->editing = false;
        Flux::toast(variant: 'success', text: 'Kontakt podaci su sačuvani.');
    }

    #[On('task-updated')]
    public function refreshGroups(): void
    {
        unset($this->groups, $this->waitingText);
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-5">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('institutions.index')" wire:navigate>Institucije</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $institution->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <header class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 lg:flex-row lg:items-start lg:justify-between dark:border-zinc-800 dark:bg-zinc-900">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ $institution->name }}</h1>
                @can('update', $institution)
                    @unless($editing)<button type="button" wire:click="startEditing" class="rounded-lg border border-zinc-200 p-1.5 text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:border-zinc-700 dark:hover:bg-zinc-800" aria-label="Uredi kontakt podatke"><flux:icon.pencil variant="micro" class="size-4" /></button>@endunless
                @endcan
            </div>
            @if($institution->type)<p class="text-sm text-zinc-500">{{ $institution->type }}</p>@endif

            @if($editing)
                <form wire:submit="saveContact" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="contact.name" label="Naziv" required />
                    <flux:input wire:model="contact.type" label="Vrsta organizacije" />
                    <flux:input wire:model="contact.address" label="Adresa" class="sm:col-span-2" />
                    <flux:input wire:model="contact.contact_person" label="Kontakt osoba" />
                    <flux:input wire:model="contact.phone" label="Telefon" />
                    <flux:input wire:model="contact.email" type="email" label="E-mail" class="sm:col-span-2" />
                    <flux:textarea wire:model="contact.notes" label="Napomena" rows="3" class="sm:col-span-2" />
                    <div class="flex gap-2 sm:col-span-2">
                        <flux:button type="submit" variant="primary" size="sm">Sačuvaj</flux:button>
                        <flux:button type="button" variant="ghost" size="sm" wire:click="$set('editing', false)">Odustani</flux:button>
                    </div>
                </form>
            @else
                <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    @foreach(['address' => ['map-pin', 'Adresa'], 'contact_person' => ['user', 'Kontakt osoba'], 'phone' => ['phone', 'Telefon'], 'email' => ['envelope', 'E-mail']] as $field => [$icon, $label])
                        @if(filled($institution->{$field}))
                            <div class="flex items-center gap-2">
                                <dt class="sr-only">{{ $label }}</dt>
                                <flux:icon :name="$icon" variant="mini" class="size-4 shrink-0 text-zinc-400" />
                                <dd class="min-w-0 truncate">
                                    @if($field === 'email')<a href="mailto:{{ $institution->email }}" class="text-blue-600 hover:underline">{{ $institution->email }}</a>
                                    @elseif($field === 'phone')<a href="tel:{{ preg_replace('/[^0-9+]/', '', $institution->phone) }}" class="hover:underline">{{ $institution->phone }}</a>
                                    @else{{ $institution->{$field} }}@endif
                                </dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
                @if(filled($institution->notes))<p class="mt-2 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $institution->notes }}</p>@endif
                @if(blank($institution->address) && blank($institution->contact_person) && blank($institution->phone) && blank($institution->email) && blank($institution->notes))
                    <p class="mt-2 text-sm text-zinc-400">Nema unesenih kontakt podataka.</p>
                @endif
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap gap-2" x-data="{ copied: false }">
            <a href="{{ route('institutions.export.xlsx', $institution) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"><flux:icon.table-cells variant="mini" class="size-4 text-green-600" />Izvoz u Excel</a>
            <button type="button" x-on:click="navigator.clipboard.writeText($refs.waitingText.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" @disabled($this->groups['waiting']->isEmpty())
                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                title="Spisak stavki koje čekaju odgovor (projekat, stavka, datum predaje, broj protokola) za e-mail ili poziv">
                <flux:icon.clipboard-document variant="mini" class="size-4 text-zinc-500" /><span x-text="copied ? 'Kopirano' : 'Kopiraj listu'">Kopiraj listu</span>
            </button>
            <textarea x-ref="waitingText" class="sr-only" tabindex="-1" aria-hidden="true" readonly>{{ $this->waitingText }}</textarea>
        </div>
    </header>

    @php($total = collect($this->groups)->sum(fn ($items) => $items->count()))
    @if($total === 0)
        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
            <flux:icon.check-circle class="mx-auto size-10 text-zinc-400" />
            <flux:heading class="mt-3">Nema otvorenih stavki prema ovoj instituciji</flux:heading>
            <flux:text class="mt-1">Prikazuju se samo stavke iz projekata koje možete vidjeti.</flux:text>
        </div>
    @endif

    @foreach(App\Services\InstitutionOverview::GROUPS as $key => $label)
        @php($items = $this->groups[$key])
        @continue($items->isEmpty())
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:key="group-{{ $key }}" aria-labelledby="grupa-{{ $key }}">
            <h2 id="grupa-{{ $key }}" class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800 dark:bg-zinc-800/50">
                <span @class(['size-2 rounded-full', 'bg-orange-400' => $key === 'waiting', 'bg-red-500' => in_array($key, ['overdue', 'supplement'], true), 'bg-sky-400' => $key === 'to_submit'])></span>
                {{ $label }} <span class="font-normal text-zinc-500">({{ $items->count() }})</span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-zinc-500">
                            <th class="px-3 py-2 font-medium">Projekat</th>
                            <th class="px-3 py-2 font-medium">Postupak</th>
                            <th class="px-3 py-2 font-medium">Stavka</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Rok</th>
                            <th class="px-3 py-2 font-medium">Predato</th>
                            <th class="px-3 py-2 font-medium">Dana čeka</th>
                            <th class="px-3 py-2 font-medium">Odgovorna osoba</th>
                            <th class="px-3 py-2 font-medium">Dok.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($items as $item)
                            @php($case = $item->administrativeCase)
                            <tr wire:key="inst-item-{{ $item->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-3 py-2"><a href="{{ route('projects.show', $case->project_id) }}" wire:navigate class="hover:text-blue-600">{{ $case->project->name }}</a></td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-300">{{ $case->name }}</td>
                                <td class="max-w-72 px-3 py-2">
                                    <a href="{{ $item->url() }}" x-on:click="if (! $event.metaKey && ! $event.ctrlKey && window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); $dispatch('open-task', { id: {{ $item->id }} }) }" class="font-medium hover:text-blue-600">{{ $item->name }}</a>
                                </td>
                                <td class="px-3 py-2"><x-item-status-menu :item="$item" :editable="$this->canEditItem($item)" /></td>
                                <td @class(['px-3 py-2 tabular-nums', 'font-semibold text-red-600 dark:text-red-400' => $item->isOverdue(), 'text-orange-600 dark:text-orange-400' => ! $item->isOverdue() && $item->isDueSoon()])>{{ $item->due_date?->format('d.m.Y.') ?? '–' }}</td>
                                <td class="px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $item->submitted_at?->format('d.m.Y.') ?? '–' }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $item->daysWaiting() ?? '–' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-300">{{ $item->responsible?->shortName() ?? '–' }}</td>
                                <td class="px-3 py-2">
                                    <button type="button" x-on:click="$dispatch('open-task', { id: {{ $item->id }}, section: 'dokumenti' })" class="inline-flex items-center gap-1 rounded-md border border-zinc-200 px-1.5 py-0.5 text-xs text-zinc-500 hover:border-blue-300 dark:border-zinc-700" aria-label="Dokumenti stavke ({{ $item->documents_count }})"><flux:icon.paper-clip variant="micro" class="size-3.5" />{{ $item->documents_count }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
