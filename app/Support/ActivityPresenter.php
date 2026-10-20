<?php

namespace App\Support;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Institution;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Čitljiv prikaz zapisa iz loga aktivnosti, npr.
 * "Ensar M.: promijenjen status iz U pripremi u Predato".
 * Koristi se bezlični oblik ("promijenjen") jer rod korisnika nije poznat.
 */
class ActivityPresenter
{
    /** Filter vrste promjene (puna historija projekta). */
    public const TYPES = [
        'status' => 'Promjene statusa',
        'deadline' => 'Rokovi',
        'assignment' => 'Odgovorna osoba',
        'submission' => 'Predaja',
        'response' => 'Odgovor',
        'document' => 'Dokumenti',
        'link' => 'Linkovi',
        'comment' => 'Komentari',
        'project' => 'Projekat (stanje, korak, procjena, članovi)',
        'created' => 'Kreiranje i brisanje',
    ];

    private const FIELDS = [
        'status' => 'status',
        'priority' => 'prioritet',
        'due_date' => 'rok',
        'responsible_user_id' => 'odgovorna osoba',
        'submitted_at' => 'datum predaje',
        'response_at' => 'datum odgovora',
        'name' => 'naziv',
        'description' => 'opis',
        'institution_id' => 'institucija',
        'recipient' => 'primalac',
        'progress' => 'ručna procjena stanja',
        'current_state' => 'trenutno stanje',
        'next_step' => 'sljedeći korak',
    ];

    /** @var array<string, string> */
    private static array $names = [];

    public static function sentence(ProjectActivity $activity): string
    {
        $actor = $activity->user?->shortName() ?? 'Sistem';
        $field = $activity->metadata['field'] ?? null;

        if ($field === null || ! isset(self::FIELDS[$field])) {
            return $actor.': '.self::lowerFirst($activity->title);
        }

        $label = self::FIELDS[$field];
        $old = self::value($field, $activity->metadata['old'] ?? null);
        $new = self::value($field, $activity->metadata['new'] ?? null);

        return match (true) {
            in_array($field, ['description', 'current_state', 'next_step'], true) && $new !== null => "{$actor}: promijenjen {$label}: „{$new}“",
            in_array($field, ['description'], true) => "{$actor}: uklonjen {$label}",
            $old === null && $new !== null => "{$actor}: postavljen {$label} {$new}",
            $old !== null && $new === null => "{$actor}: uklonjen {$label} ({$old})",
            default => "{$actor}: promijenjen {$label} iz {$old} u {$new}",
        };
    }

    /** Na čemu je promjena napravljena (za listu historije). */
    public static function subjectLabel(ProjectActivity $activity): ?string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof AdministrativeCaseItem => 'Stavka: '.$subject->name,
            $subject instanceof AdministrativeCase => 'Postupak: '.$subject->name,
            default => null,
        };
    }

    public static function applyTypeFilter(Builder $query, string $type): Builder
    {
        $field = fn (string $name) => fn (Builder $q) => $q->where('metadata->field', $name);

        return match ($type) {
            'status' => $query->where(fn (Builder $q) => $q->where('event_type', 'like', '%status%')),
            'deadline' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.due_date')->orWhere('event_type', 'like', '%deadline%')),
            'assignment' => $query->where('event_type', 'task.responsible_user_id'),
            'submission' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.submitted_at')->orWhere('event_type', 'task.submitted')),
            'response' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.response_at')->orWhere('event_type', 'like', '%response%')),
            'document' => $query->where('event_type', 'like', 'document%'),
            'link' => $query->where('event_type', 'like', 'link.%'),
            'comment' => $query->where('event_type', 'like', 'comment.%'),
            'project' => $query->where(fn (Builder $q) => $q->where('event_type', 'like', 'project.%')->orWhere('event_type', 'like', 'progress%')->orWhere('event_type', 'like', 'next_step%')),
            'created' => $query->where(fn (Builder $q) => $q->where('event_type', 'like', '%created')->orWhere('event_type', 'like', '%deleted')),
            default => $query,
        };
    }

    private static function value(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'status' => CaseItemStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'priority' => CaseItemPriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'due_date', 'submitted_at', 'response_at' => Carbon::parse($value)->format('d.m.Y.'),
            'responsible_user_id' => self::$names['user'.$value] ??= User::find($value)?->shortName() ?? 'bivši korisnik',
            'institution_id' => self::$names['institution'.$value] ??= Institution::find($value)?->name ?? '–',
            'progress' => $value.'%',
            default => (string) $value,
        };
    }

    private static function lowerFirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
