<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** Prilog (fajl) na zadatku, predmetu ili komentaru. Čuva se na privatnom disku. */
#[Fillable(['attachable_type', 'attachable_id', 'user_id', 'original_name', 'path', 'mime_type', 'size'])]
class Attachment extends Model
{
    public const DISK = 'local';

    protected static function booted(): void
    {
        // Brisanje zapisa uvijek briše i fajl s diska.
        static::deleted(fn (Attachment $attachment) => Storage::disk(self::DISK)->delete($attachment->path));
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'Bivši korisnik']);
    }

    public function project(): ?Project
    {
        return self::projectOf($this->attachable);
    }

    /** Projekat za zadatak, predmet ili komentar (za provjeru pristupa). */
    public static function projectOf(?Model $model): ?Project
    {
        return match (true) {
            $model instanceof AdministrativeCaseItem => $model->administrativeCase?->project,
            $model instanceof AdministrativeCase => $model->project,
            $model instanceof Comment => self::projectOf($model->commentable),
            default => null,
        };
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

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /** Heroicon ime prema vrsti fajla. */
    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'photo',
            $this->isPdf() => 'document-text',
            in_array($this->extension(), ['xls', 'xlsx', 'csv'], true) => 'table-cells',
            in_array($this->extension(), ['zip'], true) => 'archive-box',
            in_array($this->extension(), ['dwg', 'dxf'], true) => 'cube',
            in_array($this->extension(), ['ppt', 'pptx'], true) => 'presentation-chart-bar',
            default => 'document',
        };
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
