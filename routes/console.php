<?php

use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Services\NotificationDispatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

/** Otvoreni zadaci (stavke) aktivnih predmeta koji imaju odgovornu osobu. */
$openItems = fn (): Builder => AdministrativeCaseItem::query()
    ->with(['responsible', 'administrativeCase'])
    ->where('is_active', true)
    ->whereNotNull('responsible_user_id')
    ->whereNotIn('status', CaseItemStatus::closedValues())
    ->whereHas('administrativeCase', fn (Builder $query) => $query->where('is_archived', false));

Artisan::command('items:send-deadline-reminders', function (NotificationDispatchService $dispatcher) use ($openItems) {
    // Plan: obavijest kad je rok sutra (konfigurabilno) ili kad je istekao; svaka samo jednom po roku.
    $daysBefore = max(0, (int) config('projectflow.reminders.deadline_days_before', 1));

    $openItems()
        ->whereNull('deadline_reminded_at')
        ->whereDate('due_date', '>=', local_today())
        ->whereDate('due_date', '<=', local_today()->addDays($daysBefore))
        ->lazyById()
        ->each(function (AdministrativeCaseItem $item) use ($dispatcher) {
            $when = match (true) {
                $item->due_date->equalTo(local_today()) => 'Rok ističe danas',
                $item->due_date->equalTo(local_today()->addDay()) => 'Rok je sutra',
                default => 'Rok je '.$item->due_date->format('d.m.Y.'),
            };
            $dispatcher->dispatch([$item->responsible], NotificationKind::DeadlineSoon, $when.': '.$item->name, $item->url(), $item->due_date->format('Y-m-d'));
            $item->update(['deadline_reminded_at' => now()]);
        });

    $openItems()
        ->whereNull('overdue_reminded_at')
        ->whereDate('due_date', '<', local_today())
        ->lazyById()
        ->each(function (AdministrativeCaseItem $item) use ($dispatcher) {
            $dispatcher->dispatch([$item->responsible], NotificationKind::DeadlineOverdue, 'Rok je istekao: '.$item->name, $item->url(), $item->due_date->format('Y-m-d'));
            $item->update(['overdue_reminded_at' => now()]);
        });
})->purpose('Pošalji obavijesti za rokove stavki (rok sutra, rok istekao)');

Artisan::command('cases:send-deadline-reminders', function (NotificationDispatchService $dispatcher) {
    AdministrativeCase::query()
        ->with('responsible')
        ->where('is_archived', false)
        ->whereNull('closed_at')
        ->whereNull('deadline_reminded_at')
        ->whereNotNull('reminder_days_before')
        ->whereDate('response_due_at', '>=', local_today())
        ->lazyById()
        ->filter(fn (AdministrativeCase $case) => $case->response_due_at->lte(local_today()->addDays($case->reminder_days_before)))
        ->each(function (AdministrativeCase $case) use ($dispatcher) {
            $dispatcher->dispatch([$case->responsible], NotificationKind::DeadlineSoon, 'Približava se rok postupka: '.$case->name, $case->url(), $case->response_due_at->format('Y-m-d'));
            $case->update(['deadline_reminded_at' => now()]);
        });

    AdministrativeCase::query()
        ->with('responsible')
        ->where('is_archived', false)
        ->whereNull('closed_at')
        ->whereNull('overdue_reminded_at')
        ->whereDate('response_due_at', '<', local_today())
        ->lazyById()
        ->each(function (AdministrativeCase $case) use ($dispatcher) {
            $dispatcher->dispatch([$case->responsible], NotificationKind::DeadlineOverdue, 'Rok postupka je istekao: '.$case->name, $case->url(), $case->response_due_at->format('Y-m-d'));
            $case->update(['overdue_reminded_at' => now()]);
        });
})->purpose('Pošalji podsjetnike i upozorenja za rokove postupaka');

Artisan::command('items:send-waiting-reminders', function (NotificationDispatchService $dispatcher) {
    // Stavka čeka odgovor duže od N dana (plan: 10); podsjetnik se ponavlja svakih N dana dok čeka.
    $defaultInterval = max(1, (int) config('projectflow.reminders.waiting_too_long_days', 10));

    AdministrativeCaseItem::query()
        ->with(['administrativeCase.responsible', 'responsible'])
        ->where('is_active', true)
        ->where('status', CaseItemStatus::WaitingResponse->value)
        ->whereHas('administrativeCase', fn ($query) => $query->where('is_archived', false))
        ->lazyById()
        // Interval se računa od posljednjeg podsjetnika, odnosno od početka čekanja.
        ->filter(fn (AdministrativeCaseItem $item) => ($item->response_reminded_at ?? $item->submitted_at ?? $item->updated_at)
            ->lte(now()->subDays(max(1, $item->response_reminder_interval ?? $defaultInterval))))
        ->each(function (AdministrativeCaseItem $item) use ($dispatcher) {
            $recipients = collect([$item->responsible, $item->administrativeCase->responsible])->filter()->unique('id')->all();
            $days = $item->daysWaiting();
            $title = $days !== null ? 'Stavka čeka odgovor '.$days.' dana: '.$item->name : 'Stavka predugo čeka odgovor: '.$item->name;
            $dispatcher->dispatch($recipients, NotificationKind::WaitingTooLong, $title, $item->url(), $item->due_date?->format('Y-m-d'));
            $item->update(['response_reminded_at' => now()]);
        });
})->purpose('Pošalji podsjetnike za stavke koje predugo čekaju odgovor');

Artisan::command('notifications:send-daily-digest {--all : Pošalji svima s uključenim sažetkom, bez obzira na odabrani sat}', function () use ($openItems) {
    $waitingLimit = (int) config('projectflow.reminders.waiting_too_long_days', 14);
    $dueSoonDays = (int) config('projectflow.reminders.due_soon_days', 7);
    $describe = fn (AdministrativeCaseItem $item) => $item->name.' ('.$item->administrativeCase->project->name.' / '.$item->administrativeCase->name.($item->due_date ? ', rok '.$item->due_date->format('d.m.Y.') : '').')';

    User::query()
        ->whereNotNull('email')
        ->whereHas('notificationSetting', fn ($query) => $query
            ->where('daily_digest', true)
            ->where('email_enabled', true)
            ->when(! $this->option('all'), fn ($query) => $query->where('digest_hour', local_time(now())->hour)))
        ->lazyById()
        ->each(function (User $user) use ($openItems, $waitingLimit, $dueSoonDays, $describe) {
            $projectIds = Project::visibleTo($user)->select('id');
            $myItems = fn () => $openItems()->with('administrativeCase.project')->where('responsible_user_id', $user->id)->orderBy('due_date')->limit(10);

            $overdueItems = $myItems()->whereDate('due_date', '<', local_today())->get()->map($describe)->all();
            $dueSoonItems = $myItems()->whereBetween('due_date', [local_today(), local_today()->addDays($dueSoonDays)])->get()->map($describe)->all();

            $overdueCases = AdministrativeCase::with('project')->whereIn('project_id', $projectIds)
                ->where('is_archived', false)->whereNull('closed_at')->whereDate('response_due_at', '<', local_today())
                ->orderBy('response_due_at')->limit(10)->get()
                ->map(fn (AdministrativeCase $case) => $case->name.' ('.$case->project->name.', rok '.$case->response_due_at->format('d.m.Y.').')')->all();

            $waitingItems = $openItems()->with('administrativeCase.project')
                ->where('status', CaseItemStatus::WaitingResponse->value)
                ->whereIn('administrative_case_id', AdministrativeCase::query()->whereIn('project_id', $projectIds)->select('id'))
                ->where(fn ($query) => $query->whereNull('submitted_at')->orWhereDate('submitted_at', '<', local_today()->subDays($waitingLimit)))
                ->limit(10)->get()->map($describe)->all();

            if ($overdueItems === [] && $dueSoonItems === [] && $overdueCases === [] && $waitingItems === []) {
                return;
            }

            $user->notify(new DailyDigestNotification(local_today()->format('d.m.Y.'), $overdueCases, $overdueItems, $dueSoonItems, $waitingItems));
        });
})->purpose('Pošalji dnevni sažetak otvorenih obaveza');

// Raspored (scheduler) je u App\Console\Kernel::schedule().
