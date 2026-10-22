<!DOCTYPE html>
<html lang="bs">
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#18181b;font-size:11px}h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;margin:22px 0 8px;border-bottom:1px solid #d4d4d8;padding-bottom:5px}h3{font-size:12px;margin:16px 0 4px}.muted{color:#71717a}.meta{width:100%;border-collapse:collapse}.meta td{padding:4px 8px 4px 0;vertical-align:top}.list{width:100%;border-collapse:collapse}.list th,.list td{text-align:left;border-bottom:1px solid #e4e4e7;padding:5px 4px}.list th{background:#f4f4f5}.danger{color:#b91c1c}.done{color:#71717a;text-decoration:line-through}.footer{margin-top:28px;font-size:9px;color:#71717a}</style></head>
<body>
    @php($progress = $project->administrativeProgressPercentage())
    <table style="width:100%;margin-bottom:14px"><tr><td>@if(extension_loaded('gd'))<img src="{{ public_path('img/logo-light.png') }}" alt="Media Sky" style="height:42px">@else<strong style="font-size:16px;color:#1d6fb8">Media Sky</strong> <span style="color:#6cb33f">Telekomunikacije</span>@endif</td><td style="text-align:right" class="muted">{{ config('app.name') }}</td></tr></table>
    <h1>{{ $project->name }}</h1><div class="muted">{{ $project->code }} · Izvještaj generisan {{ local_time(now())->format('d.m.Y. H:i') }}</div>

    <h2>Osnovne informacije</h2>
    <table class="meta">
        <tr><td><strong>Status:</strong> {{ $project->status->label() }}</td><td><strong>Napredak:</strong> {{ $progress === null ? 'Nije definisan' : $progress.'%' }}</td></tr>
        <tr><td><strong>Kategorija:</strong> {{ $project->category->name }}</td><td><strong>Odgovorna osoba:</strong> {{ $project->responsible->name }}</td></tr>
        <tr><td><strong>Početak:</strong> {{ $project->start_date->format('d.m.Y.') }}</td><td><strong>Planirani završetak:</strong> {{ $project->planned_end_date?->format('d.m.Y.') ?: 'Nije određen' }}</td></tr>
    </table>
    @if($project->description)<p>{{ $project->description }}</p>@endif

    <h2>Postupci i stavke</h2>
    @forelse($project->administrativeCases as $case)
        <h3>{{ $case->name }} <span class="muted">· {{ $case->status->name }}{{ $case->institution ? ' · '.$case->institution->name : '' }}{{ $case->response_due_at ? ' · rok '.$case->response_due_at->format('d.m.Y.') : '' }} · {{ $case->progressPercentage() }}%</span></h3>
        <table class="list">
            <thead><tr><th>Stavka</th><th>Status</th><th>Odgovorna osoba</th><th>Rok</th></tr></thead>
            <tbody>
                @forelse($case->items as $item)
                    <tr>
                        <td class="{{ $item->status === App\Enums\CaseItemStatus::Completed ? 'done' : '' }}">{{ $item->name }}</td>
                        <td>{{ $item->status->label() }}</td>
                        <td>{{ $item->responsible?->name ?? '—' }}</td>
                        <td class="{{ $item->isOverdue() ? 'danger' : '' }}">{{ $item->due_date?->format('d.m.Y.') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Postupak nema stavki.</td></tr>
                @endforelse
            </tbody>
        </table>
    @empty
        <p class="muted">Projekat nema postupaka.</p>
    @endforelse

    <h2>Dokumenti</h2>
    <table class="list"><thead><tr><th>Dokument</th><th>Broj protokola</th><th>Datum</th></tr></thead><tbody>@forelse($project->documentReferences as $document)<tr><td>{{ $document->title }}</td><td>{{ $document->protocol_number ?: '—' }}</td><td>{{ $document->document_date?->format('d.m.Y.') ?: '—' }}</td></tr>@empty<tr><td colspan="3">Nema povezanih dokumenata.</td></tr>@endforelse</tbody></table>

    <div class="footer">{{ config('app.name') }} · Izvještaj sadrži podatke evidentirane u sistemu u trenutku generisanja.</div>
</body></html>
