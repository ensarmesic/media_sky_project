<?php

use App\Enums\NotificationKind;
use App\Services\NotificationDispatchService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Zvono u gornjoj traci: broj nepročitanih i padajuća lista posljednjih obavijesti. */
new class extends Component {
    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /** @return Collection<int, DatabaseNotification> */
    #[Computed]
    public function latest(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(8)->get();
    }

    /** Klik na obavijest: označi pročitanom i otvori stavku/komentar. */
    public function open(string $id): void
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $this->redirect(NotificationDispatchService::safeUrl($notification->data['url'] ?? null), navigate: true);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->unreadCount, $this->latest);
    }

    #[On('notifications-changed')]
    public function refreshList(): void
    {
        unset($this->unreadCount, $this->latest);
    }
}; ?>

<div x-data="{ open: false }" class="relative mr-2" x-on:keydown.escape="open = false" wire:poll.60s.visible>
    <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true"
        class="relative rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
        aria-label="Obavijesti{{ $this->unreadCount ? ' ('.$this->unreadCount.' nepročitanih)' : '' }}">
        <flux:icon.bell class="size-5" />
        @if($this->unreadCount)
            <span class="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity x-on:click.outside="open = false"
        class="absolute right-0 z-50 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-label="Obavijesti">
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
            <span class="text-sm font-semibold">Obavijesti</span>
            @if($this->unreadCount)
                <button type="button" wire:click="markAllAsRead" class="text-xs font-medium text-blue-600 hover:underline">Označi sve kao pročitano</button>
            @endif
        </div>
        <ul class="max-h-96 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
            @forelse($this->latest as $notification)
                @php($kind = NotificationKind::tryFrom($notification->data['kind'] ?? ''))
                <li wire:key="bell-{{ $notification->id }}">
                    <button type="button" wire:click="open('{{ $notification->id }}')" @class(['flex w-full gap-3 px-4 py-3 text-left hover:bg-zinc-50 focus:outline-none focus-visible:bg-zinc-50 dark:hover:bg-zinc-800 dark:focus-visible:bg-zinc-800', 'bg-blue-50/60 dark:bg-blue-950/20' => ! $notification->read_at])>
                        <span @class(['mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full', 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' => ! $notification->read_at, 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => $notification->read_at])>
                            <flux:icon :name="$kind?->icon() ?? 'bell'" variant="micro" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span @class(['line-clamp-2 text-sm', 'font-semibold' => ! $notification->read_at])>{{ $notification->data['title'] ?? 'Obavijest' }}</span>
                            <span class="mt-0.5 block text-xs text-zinc-500">{{ $notification->created_at->format('d.m.Y. H:i') }}</span>
                        </span>
                        @unless($notification->read_at)<span class="mt-2 size-2 shrink-0 rounded-full bg-blue-500" aria-label="Nepročitano"></span>@endunless
                    </button>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sm text-zinc-500">Nemate obavijesti.</li>
            @endforelse
        </ul>
        <a href="{{ route('notifications.index') }}" wire:navigate class="block border-t border-zinc-100 px-4 py-2.5 text-center text-sm font-medium text-blue-600 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800">Sve obavijesti</a>
    </div>
</div>
