<?php

namespace App\Support;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Institution;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Čitljiv prikaz zapisa iz loga aktivnosti, npr.
 * "Ensar M. je promijenio status iz U pripremi u Predato".
 * Glagol prati oblik obraćanja korisnika (profil); bez njega "je promijenio/la".
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

    /** Praćena polja → naziv u akuzativu ("je promijenio <rok>"). */
    private const FIELDS = [
        'status' => 'status',
        'project_status' => 'status projekta',
        'priority' => 'prioritet',
        'due_date' => 'rok',
        'responsible_user_id' => 'odgovornu osobu',
        'submitted_at' => 'datum predaje',
        'response_at' => 'datum odgovora',
        'name' => 'naziv',
        'description' => 'opis',
        'institution_id' => 'instituciju',
        'institution' => 'instituciju',
        'recipient' => 'primaoca',
        'progress' => 'ručnu procjenu stanja',
        'current_state' => 'trenutno stanje',
        'next_step' => 'sljedeći korak',
    ];

    /** Polja čiji se novi tekst navodi umjesto "iz … u …". */
    private const TEXT_FIELDS = ['description', 'current_state', 'next_step'];

    /**
     * Događaji bez praćenog polja: [glagol (muški rod), objekat].
     * ":name" je dio naslova iza dvotačke (npr. naziv stavke ili dokumenta).
     */
    private const EVENTS = [
        'task.created' => ['kreirao', 'stavku :name'],
        'task.deleted' => ['obrisao', 'stavku :name'],
        'case.created' => ['kreirao', 'postupak :name'],
        'case_created' => ['kopirao', 'postupak :name'],
        'case_updated' => ['izmijenio', 'postupak :name'],
        'case_deadline_changed' => ['promijenio', 'rok postupka :name'],
        'case_status_changed' => ['promijenio', 'status postupka :name'],
        'case_comment' => ['dodao', 'bilješku na postupak :name'],
        'case_archived' => ['arhivirao', 'postupak :name'],
        'case_restored' => ['vratio', 'postupak :name iz arhive'],
        'case_items_copied' => ['kopirao', 'stavke iz postupka :name'],
        'comment.created' => ['dodao', 'komentar'],
        'comment.updated' => ['uredio', 'komentar'],
        'comment.deleted' => ['obrisao', 'komentar'],
        'document.uploaded' => ['dodao', 'dokument :name'],
        'document.version' => ['dodao', 'novu verziju dokumenta :name'],
        'document.deleted' => ['obrisao', 'dokument :name'],
        'document_linked' => ['evidentirao', 'dokument :name'],
        'link.added' => ['dodao', 'link :name'],
        'link.updated' => ['izmijenio', 'link :name'],
        'link.deleted' => ['obrisao', 'link :name'],
        'project_created' => ['kreirao', 'projekat'],
        'project_updated' => ['izmijenio', 'podatke projekta'],
        'status_changed' => ['promijenio', 'status projekta'],
        'project.member_added' => ['dodao', 'člana projekta :name'],
        'project.member_role' => ['promijenio', 'ulogu člana :name'],
        'project.member_removed' => ['uklonio', 'člana projekta :name'],
    ];

    /** @var array<string, string> */
    private static array $names = [];

    public static function sentence(ProjectActivity $activity): string
    {
        $user = $activity->user;
        $actor = $user?->shortName() ?? 'Sistem';
        $verb = fn (string $masculine) => $actor.' je '.($user?->verb($masculine) ?? $masculine);
        $field = $activity->metadata['field'] ?? null;

        if ($field !== null && isset(self::FIELDS[$field])) {
            $label = self::FIELDS[$field];
            $old = self::value($field, $activity->metadata['old'] ?? null);
            $new = self::value($field, $activity->metadata['new'] ?? null);

            return match (true) {
                in_array($field, self::TEXT_FIELDS, true) && $new !== null => $verb('promijenio')." {$label}: „{$new}“",
                $new === null => $verb('uklonio')." {$label}".($old !== null && ! in_array($field, self::TEXT_FIELDS, true) ? " ({$old})" : ''),
                $old === null => $verb('postavio')." {$label} {$new}",
                default => $verb('promijenio')." {$label} iz {$old} u {$new}",
            };
        }

        if (isset(self::EVENTS[$activity->event_type])) {
            [$masculine, $object] = self::EVENTS[$activity->event_type];
            $name = str_contains($activity->title, ': ') ? '„'.trim(explode(': ', $activity->title, 2)[1]).'“' : '';

            return $verb($masculine).' '.trim(preg_replace('/\s+/', ' ', str_replace(':name', $name, $object)));
        }

        return $actor.': '.self::lowerFirst($activity->title);
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

    /**
     * @param  Builder<ProjectActivity>  $query
     * @return Builder<ProjectActivity>
     */
    public static function applyTypeFilter(Builder $query, string $type): Builder
    {
        return match ($type) {
            'status' => $query->where(fn (Builder $q) => $q->where('event_type', 'like', '%status%')),
            'deadline' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.due_date')->orWhere('event_type', 'like', '%deadline%')),
            'assignment' => $query->where('event_type', 'task.responsible_user_id'),
            'submission' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.submitted_at')->orWhere('event_type', 'task.submitted')),
            'response' => $query->where(fn (Builder $q) => $q->where('event_type', 'task.response_at')->orWhere('event_type', 'like', '%response%')),
            'document' => $query->where('event_type', 'like', 'document%'),
            'link' => $query->where('event_type', 'like', 'link.%'),
            'comment' => $query->where('event_type', 'like', 'comment.%'),
            'project' => $query->where(fn (Builder $q) => $q->where('event_type', 'like', 'project%')->orWhere('event_type', 'like', 'progress%')->orWhere('event_type', 'like', 'next_step%')),
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
            'project_status' => ProjectStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'priority' => CaseItemPriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'due_date', 'submitted_at', 'response_at' => Carbon::parse($value)->format('d.m.Y.'),
            'responsible_user_id' => self::$names['user'.$value] ??= User::find((int) $value)?->shortName() ?? 'bivši korisnik',
            'institution_id' => self::$names['institution'.$value] ??= Institution::find((int) $value)->name ?? '–',
            'progress' => $value.'%',
            default => (string) $value,
        };
    }

    private static function lowerFirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
