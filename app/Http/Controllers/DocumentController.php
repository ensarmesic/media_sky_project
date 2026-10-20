<?php

namespace App\Http\Controllers;

use App\Models\ProjectDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pristup dokumentima isključivo kroz rute s provjerom pristupa (privatni disk). */
class DocumentController extends Controller
{
    /** Pregled u pregledniku za PDF i slike; ostale vrste se preuzimaju. */
    public function show(ProjectDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);
        $disk = Storage::disk(ProjectDocument::DISK);
        abort_unless($disk->exists($document->path), 404);

        if (! $document->isPreviewable()) {
            return $this->download($document);
        }

        return $disk->response($document->path, $this->filename($document), [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
        ], 'inline');
    }

    public function download(ProjectDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);
        $disk = Storage::disk(ProjectDocument::DISK);
        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $this->filename($document), ['X-Content-Type-Options' => 'nosniff']);
    }

    /** Naziv za preuzimanje; ekstenzija se dodaje ako je korisnik dao naziv bez nje. */
    private function filename(ProjectDocument $document): string
    {
        $extension = $document->extension();

        return $extension !== '' && ! str_ends_with(mb_strtolower($document->name), '.'.$extension)
            ? $document->name.'.'.$extension
            : $document->name;
    }
}
