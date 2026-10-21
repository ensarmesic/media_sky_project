<?php

namespace App\Exports;

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectDocument;
use App\Support\ActivityPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Izvoz projekta u XLSX: listovi "Postupci", "Stavke" i "Dokumenti".
 * Filter statusa postupka (isti kao tabovi na detalju projekta) ograničava sva tri lista.
 */
final class ProjectExport
{
    public function __construct(private Project $project, private string $caseFilter = 'all') {}

    /** Naziv fajla: <kod-projekta>_<dd-mm-yyyy>.xlsx */
    public function filename(): string
    {
        $code = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', Str::ascii($this->project->code ?: $this->project->name)), '-');

        return ($code !== '' ? $code : 'projekat').'_'.now()->format('d-m-Y').'.xlsx';
    }

    public function store(string $path): void
    {
        $cases = $this->cases();
        $workbook = new XlsxWorkbook($path);
        $workbook->sheet('Postupci', [
            'Postupak' => 32, 'Institucija' => 26, 'Broj stavki' => 11, 'Završeno' => 10, '%' => 7, 'Status' => 16,
            'Posljednja aktivnost' => 12, 'Opis posljednje aktivnosti' => 50,
        ], $this->caseRows($cases));

        $items = $cases->flatMap(fn (AdministrativeCase $case) => $case->items->values()->map(fn (AdministrativeCaseItem $item, int $i) => ItemRows::row($item, $i + 1)));
        $workbook->sheet('Stavke', ItemRows::columns(), $items);

        $workbook->sheet('Dokumenti', [
            'Naziv' => 36, 'Smjer' => 10, 'Broj protokola' => 18, 'Datum' => 12, 'Verzija' => 8, 'Postupak / stavka' => 40, 'Uploadovao' => 22,
        ], $this->documentRows($cases));
        $workbook->close();
    }

    /** @return Collection<int, AdministrativeCase> */
    private function cases(): Collection
    {
        $cases = $this->project->administrativeCases()
            ->where('is_archived', false)
            ->with(['institution:id,name', 'items' => fn ($query) => $query->where('is_active', true)->orderBy('position')->with(['institution:id,name', 'responsible:id,name'])->withCount('documents')])
            ->get();
        $cases->each(fn (AdministrativeCase $case) => $case->setRelation('project', $this->project)->items->each->setRelation('administrativeCase', $case));

        return $this->caseFilter === 'all' ? $cases : $cases->filter(fn (AdministrativeCase $case) => $case->progressStatus()->value === $this->caseFilter)->values();
    }

    /**
     * @param  Collection<int, AdministrativeCase>  $cases
     * @return \Generator<int, list<mixed>>
     */
    private function caseRows(Collection $cases): \Generator
    {
        $itemIds = $cases->flatMap(fn (AdministrativeCase $case) => $case->items->modelKeys());
        $activities = ProjectActivity::query()->where('project_id', $this->project->id)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', (new AdministrativeCase)->getMorphClass())->whereIn('subject_id', $cases->modelKeys()))
                ->orWhere(fn ($q) => $q->where('subject_type', (new AdministrativeCaseItem)->getMorphClass())->whereIn('subject_id', $itemIds)))
            ->with('user:id,name,grammatical_gender')->latest()->latest('id')->get();
        $caseOfItem = $cases->flatMap(fn (AdministrativeCase $case) => $case->items->mapWithKeys(fn (AdministrativeCaseItem $item) => [$item->id => $case->id]));
        $lastByCase = $activities->groupBy(fn (ProjectActivity $a) => $a->subject_type === (new AdministrativeCase)->getMorphClass() ? (int) $a->subject_id : (int) ($caseOfItem[$a->subject_id] ?? 0))->map->first();

        foreach ($cases as $case) {
            $counts = $case->itemCounts();
            $status = $case->progressStatus();
            $last = $lastByCase->get($case->id);
            yield [
                $case->name, $case->institution?->name, $counts['total'], $counts['done'], $counts['percent'],
                XlsxWorkbook::status($status->label(), $status->color()),
                $last?->created_at, $last !== null ? ActivityPresenter::sentence($last) : null,
            ];
        }
    }

    /**
     * @param  Collection<int, AdministrativeCase>  $cases
     * @return \Generator<int, list<mixed>>
     */
    private function documentRows(Collection $cases): \Generator
    {
        $caseIds = $cases->modelKeys();
        $itemIds = $cases->flatMap(fn (AdministrativeCase $case) => $case->items->modelKeys())->all();
        $documents = ProjectDocument::query()
            ->where('project_id', $this->project->id)
            ->whereNull('replaced_by_id')
            ->with(['uploader:id,name', 'documentable' => fn (MorphTo $morph) => $morph->morphWith([Comment::class => ['commentable']])])
            ->orderByDesc('document_date')->orderByDesc('id')
            ->get();

        foreach ($documents as $document) {
            $owner = $document->documentable instanceof Comment ? $document->documentable->commentable : $document->documentable;
            $place = match (true) {
                $owner instanceof AdministrativeCaseItem => in_array($owner->id, $itemIds, true) ? ($cases->firstWhere('id', $owner->administrative_case_id)?->name.' › '.$owner->name) : null,
                $owner instanceof AdministrativeCase => in_array($owner->id, $caseIds, true) ? $owner->name : null,
                default => $this->caseFilter === 'all' ? 'Projekat' : null,
            };
            if ($place === null) {
                continue; // dokument postupka/stavke izvan aktivnog filtera
            }
            yield [
                $document->name, $document->directionLabel(), $document->protocol_number, $document->document_date,
                $document->version, $place, $document->uploader->name,
            ];
        }
    }
}
