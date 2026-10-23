<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Dokument (fajl) na projektu, postupku, stavci ili komentaru. Čuva se na privatnom
 * disku. Nova verzija je novi zapis; stara verzija dobija replaced_by_id i ostaje sačuvana.
 *
 * @property int $id
 * @property int $project_id
 * @property int $uploaded_by
 * @property string $name
 * @property string $path
 * @property string|null $mime_type
 * @property int $size
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $administrative_case_id
 * @property int|null $administrative_case_item_id
 * @property string|null $document_type
 * @property int $version
 * @property int|null $replaced_by_id
 * @property bool $is_archived
 * @property string|null $documentable_type
 * @property int|null $documentable_id
 * @property string|null $original_name
 * @property string|null $direction
 * @property string|null $protocol_number
 * @property CarbonImmutable|null $document_date
 */
class ProjectDocument extends Model
{
    /** @var list<string> */
    protected $fillable = ['project_id', 'documentable_type', 'documentable_id', 'administrative_case_id', 'administrative_case_item_id', 'uploaded_by', 'name', 'original_name', 'path', 'mime_type', 'size', 'description', 'document_type', 'direction', 'protocol_number', 'document_date', 'version', 'replaced_by_id', 'is_archived'];

    /** @var array<string, string> */
    protected $casts = ['size' => 'integer', 'version' => 'integer', 'replaced_by_id' => 'integer', 'is_archived' => 'boolean', 'document_date' => 'date'];

    public const DISK = 'local';

    /** Smjer dokumenta → labela. */
    public const DIRECTIONS = ['outgoing' => 'Izlazni', 'incoming' => 'Ulazni', 'internal' => 'Interni'];

    protected $attributes = ['version' => 1, 'is_archived' => false];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Vlasnik dokumenta: Project, AdministrativeCase, AdministrativeCaseItem ili Comment.
     *
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<AdministrativeCase, $this> */
    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    /** @return BelongsTo<AdministrativeCaseItem, $this> */
    public function administrativeCaseItem(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withDefault(['name' => 'Bivši korisnik']);
    }

    /** @return BelongsTo<self, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** @return HasMany<self, $this> */
    public function previousVersions(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /**
     * Stavke s kojima je datoteka dodatno povezana (pored primarne administrative_case_item_id veze).
     *
     * @return BelongsToMany<AdministrativeCaseItem, $this>
     */
    public function linkedItems(): BelongsToMany
    {
        return $this->belongsToMany(AdministrativeCaseItem::class, 'project_document_items');
    }

    /**
     * Sve starije verzije (lanac replaced_by_id unazad), najnovija prva.
     *
     * @return Collection<int, self>
     */
    public function versionHistory(): Collection
    {
        $history = new Collection;
        $current = $this;
        while ($history->count() < 50 && ($previous = self::where('replaced_by_id', $current->id)->first()) !== null) {
            $history->push($previous);
            $current = $previous;
        }

        return $history;
    }

    public function isImage(): bool
    {
        // SVG se namjerno ne prikazuje inline (može sadržavati skripte).
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isPdf();
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name ?: $this->path, PATHINFO_EXTENSION));
    }

    /** Heroicon ime prema vrsti fajla. */
    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'photo',
            $this->isPdf() => 'document-text',
            in_array($this->extension(), ['xls', 'xlsx', 'csv'], true) => 'table-cells',
            $this->extension() === 'zip' => 'archive-box',
            in_array($this->extension(), ['dwg', 'dxf'], true) => 'cube',
            in_array($this->extension(), ['ppt', 'pptx'], true) => 'presentation-chart-bar',
            default => 'document',
        };
    }

    /** Boja ikone prema vrsti fajla (PDF crveno kao na referentnom dizajnu). */
    public function iconClass(): string
    {
        return match (true) {
            $this->isPdf() => 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300',
            $this->isImage() => 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300',
            in_array($this->extension(), ['xls', 'xlsx', 'csv'], true) => 'bg-green-50 text-green-600 dark:bg-green-950/40 dark:text-green-300',
            in_array($this->extension(), ['doc', 'docx'], true) => 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300',
            default => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
        };
    }

    public function directionLabel(): ?string
    {
        return self::DIRECTIONS[$this->direction] ?? null;
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
