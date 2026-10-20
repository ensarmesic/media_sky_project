<?php

use App\Http\Controllers\ReportController;
use App\Models\AdministrativeCase;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::redirect('/', '/projekti')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Sve se radi unutar projekta; "dashboard" ostaje samo kao alias za početnu stranicu.
    Route::redirect('dashboard', '/projekti')->name('dashboard');

    Route::livewire('projekti', 'pages::projects.index')->name('projects.index');
    Route::livewire('projekti/novi', 'pages::projects.form')->name('projects.create');
    Route::livewire('projekti/{project}/uredi', 'pages::projects.form')->name('projects.edit');
    Route::get('projekti/{project}/dokumenti/{document}/preuzmi', function (Project $project, ProjectDocument $document) {
        abort_unless($document->project_id === $project->id, 404);
        Gate::authorize('view', $project);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        // Naziv dokumenta može biti unesen bez ekstenzije — preuzimamo je iz pohranjene datoteke.
        $extension = pathinfo($document->path, PATHINFO_EXTENSION);
        $filename = $extension !== '' && ! str_ends_with(mb_strtolower($document->name), '.'.mb_strtolower($extension))
            ? $document->name.'.'.$extension
            : $document->name;

        return Storage::disk('local')->download($document->path, $filename);
    })->name('projects.documents.download');
    Route::get('projekti/{project}/izvjestaj.pdf', [ReportController::class, 'projectPdf'])->name('projects.report.pdf');
    Route::livewire('projekti/{project}', 'pages::projects.show')->name('projects.show');

    // Stari linkovi na predmete (npr. iz ranije poslanih obavještenja) vode u projekat.
    Route::get('predmeti/{case}', function (AdministrativeCase $case) {
        Gate::authorize('view', $case);

        return redirect($case->url());
    })->name('cases.show');

    Route::livewire('zadaci/{item}', 'pages::tasks.show')->name('tasks.show');
    Route::livewire('moji-zadaci', 'pages::my-tasks.index')->name('my-tasks.index');
    Route::livewire('obavijesti', 'pages::notifications.index')->name('notifications.index');
});

require __DIR__.'/settings.php';
