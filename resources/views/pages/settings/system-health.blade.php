<?php

use App\Jobs\RunBackupJob;
use App\Services\SystemHealth;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Stanje sistema (samo admin): scheduler, queue, jobovi na čekanju i neuspjeli jobovi. */
new #[Title('Stanje sistema')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
    }

    #[Computed]
    public function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    /** @return Collection<int, object> */
    #[Computed]
    public function failedJobs(): Collection
    {
        return DB::table('failed_jobs')->latest('failed_at')->limit(50)->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function (object $job) {
                $payload = json_decode((string) $job->payload, true);
                $job->name = is_array($payload) ? ($payload['displayName'] ?? 'Job') : 'Job';
                $job->error = strtok((string) $job->exception, "\n") ?: '';

                return $job;
            });
    }

    public function retry(string $uuid): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        unset($this->failedJobs);
        Flux::toast('Job je vraćen u red čekanja.');
    }

    public function forget(string $uuid): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        Artisan::call('queue:forget', ['id' => $uuid]);
        unset($this->failedJobs);
        Flux::toast('Neuspjeli job je obrisan.');
    }

    public function retryAll(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        Artisan::call('queue:retry', ['id' => ['all']]);
        unset($this->failedJobs);
        Flux::toast('Svi neuspjeli jobovi su vraćeni u red čekanja.');
    }

    public function backupNow(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        RunBackupJob::dispatch();
        Flux::toast('Backup je pokrenut u pozadini. Osvježite stranicu za nekoliko minuta.');
    }

    public function forgetAll(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        Artisan::call('queue:flush');
        unset($this->failedJobs);
        Flux::toast('Svi neuspjeli jobovi su obrisani.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">Stanje sistema</flux:heading>

    <x-pages::settings.layout heading="Stanje sistema" subheading="Pozadinski procesi koji šalju obavijesti i dnevne podsjetnike.">
        @php($health = $this->health)
        @php($warnings = $health->warnings())
        <div class="space-y-6" wire:poll.30s>
            @foreach($warnings as $warning)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="$warning" />
            @endforeach

            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach([
                    ['Zadnje pokretanje schedulera', $health->schedulerLastRun()?->format('d.m.Y. H:i') ?? 'nikad', 'clock'],
                    ['Zadnji obrađeni job', $health->queueLastProcessed()?->format('d.m.Y. H:i') ?? 'nikad', 'queue-list'],
                    ['Jobova na čekanju', (string) $health->pendingJobs(), 'inbox-stack'],
                    ['Neuspjelih jobova', (string) $health->failedJobs(), 'exclamation-circle'],
                ] as [$label, $value, $icon])
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <dt class="flex items-center gap-2 text-xs text-zinc-500"><flux:icon :name="$icon" variant="micro" class="size-4" />{{ $label }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <section>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>Backup</flux:heading>
                    <flux:button size="sm" icon="circle-stack" wire:click="backupNow">Napravi backup sada</flux:button>
                </div>
                <ul class="mt-3 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach($health->backups() as $backup)
                        <li class="grid gap-1 p-3 text-sm sm:grid-cols-4" wire:key="backup-{{ $backup['disk'] }}">
                            <span class="font-medium">{{ $backup['disk'] }}@unless($backup['reachable'])<span class="ml-1 text-xs text-red-600">nedostupan</span>@endunless</span>
                            <span><span class="text-xs text-zinc-500">Zadnji uspješan:</span> {{ $backup['newest']?->format('d.m.Y. H:i') ?? 'nikad' }}</span>
                            <span><span class="text-xs text-zinc-500">Veličina:</span> {{ SystemHealth::humanBytes($backup['size']) }}</span>
                            <span><span class="text-xs text-zinc-500">Čuva se:</span> {{ $backup['count'] }} ({{ SystemHealth::humanBytes($backup['used']) }})</span>
                        </li>
                    @endforeach
                </ul>
                <flux:text class="mt-2 text-xs">Backup se pravi svaki dan u 02:00. Odredišta se biraju u <code>.env</code> (<code>BACKUP_DISKS</code>); backup samo na istom serveru nije dovoljan.</flux:text>
            </section>

            <section>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>Neuspjeli jobovi</flux:heading>
                    @if($this->failedJobs->isNotEmpty())
                        <div class="flex gap-2">
                            <flux:button size="sm" icon="arrow-path" wire:click="retryAll">Pokušaj sve ponovo</flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="forgetAll" wire:confirm="Obrisati sve neuspjele jobove?">Obriši sve</flux:button>
                        </div>
                    @endif
                </div>
                <ul class="mt-3 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @forelse($this->failedJobs as $job)
                        <li class="flex flex-col gap-2 p-3 sm:flex-row sm:items-start sm:justify-between" wire:key="failed-{{ $job->uuid }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ class_basename($job->name) }} <span class="text-xs font-normal text-zinc-500">· {{ $job->queue }} · {{ \Carbon\CarbonImmutable::parse($job->failed_at)->format('d.m.Y. H:i') }}</span></p>
                                <p class="mt-0.5 line-clamp-2 break-all text-xs text-red-600 dark:text-red-400" title="{{ $job->error }}">{{ $job->error }}</p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <flux:button size="xs" wire:click="retry('{{ $job->uuid }}')">Pokušaj ponovo</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="forget('{{ $job->uuid }}')" wire:confirm="Obrisati ovaj neuspjeli job?">Obriši</flux:button>
                            </div>
                        </li>
                    @empty
                        <li class="p-6 text-center text-sm text-zinc-500">Nema neuspjelih jobova.</li>
                    @endforelse
                </ul>
            </section>

            <flux:text class="text-xs">Isto stanje u terminalu: <code>php artisan app:health</code>. Konfiguracija queue workera i schedulera na serveru je u <code>docs/PRODUCTION.md</code>.</flux:text>
        </div>
    </x-pages::settings.layout>
</section>
