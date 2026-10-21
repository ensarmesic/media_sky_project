<?php

use App\Enums\NotificationKind;
use App\Services\NotificationDispatchService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Obavijesti')] class extends Component {
    use WithPagination;

    /** '' | neprocitane */
    #[Url(as: 'prikaz', except: '')]
    public string $show = '';

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /** Klik na obavijest: označi pročitanom i otvori stavku/komentar. */
    public function open(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);
        $notification->markAsRead();
        $this->redirect(NotificationDispatchService::safeUrl($notification->data['url'] ?? null), navigate: true);
    }

    public function markAsRead(string $notificationId): void
    {
        auth()->user()->notifications()->findOrFail($notificationId)->markAsRead();
        $this->dispatch('notifications-changed');
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('notifications-changed');
    }

    public function delete(string $notificationId): void
    {
        auth()->user()->notifications()->findOrFail($notificationId)->delete();
        $this->dispatch('notifications-changed');
    }

    public function with(): array
    {
        return [
            'notifications' => auth()->user()->notifications()->when($this->show === 'neprocitane', fn ($query) => $query->whereNull('read_at'))->latest()->paginate(20),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div><flux:heading size="xl" level="1">Obavijesti</flux:heading><flux:subheading class="mt-1">Podsjetnici i važne promjene na vašim projektima i stavkama</flux:subheading></div>
        @if($unreadCount)<flux:button wire:click="markAllAsRead" variant="ghost" icon="check">Označi sve kao pročitano</flux:button>@endif
    </div>

    <div class="flex gap-1" role="tablist" aria-label="Prikaz">
        @foreach(['' => 'Sve', 'neprocitane' => 'Nepročitane ('.$unreadCount.')'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $show === $key ? 'true' : 'false' }}" wire:click="$set('show', '{{ $key }}')" @class(['rounded-lg px-3 py-1.5 text-sm', 'bg-zinc-900 font-medium text-white dark:bg-white dark:text-zinc-900' => $show === $key, 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $show !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @forelse($notifications as $notification)
            @php($kind = NotificationKind::tryFrom($notification->data['kind'] ?? ''))
            <div wire:key="notification-{{ $notification->id }}" class="flex gap-4 border-b border-zinc-100 p-5 last:border-0 dark:border-zinc-800 {{ $notification->read_at ? '' : 'bg-blue-50/50 dark:bg-blue-950/20' }}">
                <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full {{ $notification->read_at ? 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' : 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' }}"><flux:icon :name="$kind?->icon() ?? 'bell'" class="size-4" /></div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <button type="button" wire:click="open('{{ $notification->id }}')" @class(['text-left text-sm hover:text-blue-600', 'font-semibold' => ! $notification->read_at])>{{ $notification->data['title'] ?? 'Obavijest' }}</button>
                        <time class="shrink-0 text-xs text-zinc-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('d.m.Y. H:i') }}</time>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-zinc-500">
                        @if($kind)<span>{{ $kind->label() }}</span>@endif
                        @if($notification->data['due_date'] ?? null)<span>Rok: {{ Carbon\CarbonImmutable::parse($notification->data['due_date'])->format('d.m.Y.') }}</span>@endif
                    </div>
                    <div class="mt-3 flex gap-3">@unless($notification->read_at)<button type="button" wire:click="markAsRead('{{ $notification->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Označi pročitanom</button>@endunless<button type="button" wire:click="delete('{{ $notification->id }}')" wire:confirm="Obrisati obavijest?" class="text-xs text-zinc-500 hover:text-red-600">Obriši</button></div>
                </div>
            </div>
        @empty
            <div class="flex min-h-72 flex-col items-center justify-center px-6 py-16 text-center"><flux:icon.bell-slash class="mb-4 size-10 text-zinc-400"/><flux:heading size="lg">{{ $show === 'neprocitane' ? 'Nema nepročitanih obavijesti' : 'Nema obavijesti' }}</flux:heading><flux:text class="mt-2">Novi podsjetnici pojavit će se ovdje.</flux:text></div>
        @endforelse
    </section>
    {{ $notifications->links() }}
</div>
