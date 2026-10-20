<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Obavijesti')] class extends Component {
    use WithPagination;

    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);
        $notification->markAsRead();
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function delete(string $notificationId): void
    {
        auth()->user()->notifications()->findOrFail($notificationId)->delete();
    }

    public function with(): array
    {
        return ['notifications' => auth()->user()->notifications()->latest()->paginate(20)];
    }
}; ?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div><flux:heading size="xl" level="1">Obavijesti</flux:heading><flux:subheading class="mt-1">Podsjetnici i važne promjene u vašem radnom prostoru</flux:subheading></div>
        @if(auth()->user()->unreadNotifications()->exists())<flux:button wire:click="markAllAsRead" variant="ghost" icon="check">Označi sve pročitanim</flux:button>@endif
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @forelse($notifications as $notification)
            <div wire:key="notification-{{ $notification->id }}" class="flex gap-4 border-b border-zinc-100 p-5 last:border-0 dark:border-zinc-800 {{ $notification->read_at ? '' : 'bg-blue-50/50 dark:bg-blue-950/20' }}">
                <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full {{ $notification->read_at ? 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' : 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' }}"><flux:icon.bell class="size-4" /></div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><a href="{{ $notification->data['url'] ?? route('notifications.index') }}" wire:navigate class="truncate text-sm font-semibold hover:text-blue-600">{{ $notification->data['title'] ?? 'Obavijest' }}</a><time class="shrink-0 text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</time></div>
                    @if($notification->data['due_date'] ?? null)<div class="mt-1 text-sm text-zinc-500">Rok zadatka: {{ Carbon\CarbonImmutable::parse($notification->data['due_date'])->format('d.m.Y.') }}</div>@endif
                    <div class="mt-3 flex gap-2">@unless($notification->read_at)<button type="button" wire:click="markAsRead('{{ $notification->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Označi pročitanom</button>@endunless<button type="button" wire:click="delete('{{ $notification->id }}')" wire:confirm="Obrisati obavijest?" class="text-xs text-zinc-500 hover:text-red-600">Obriši</button></div>
                </div>
            </div>
        @empty
            <div class="flex min-h-72 flex-col items-center justify-center px-6 py-16 text-center"><flux:icon.bell-slash class="mb-4 size-10 text-zinc-400"/><flux:heading size="lg">Nema obavijesti</flux:heading><flux:text class="mt-2">Novi podsjetnici pojavit će se ovdje.</flux:text></div>
        @endforelse
    </section>
    {{ $notifications->links() }}
</div>
