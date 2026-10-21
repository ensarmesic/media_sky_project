<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ReportController;
use App\Models\AdministrativeCase;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/projekti')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Sve se radi unutar projekta; "dashboard" ostaje samo kao alias za početnu stranicu.
    Route::redirect('dashboard', '/projekti')->name('dashboard');

    Route::livewire('projekti', 'pages::projects.index')->name('projects.index');
    Route::livewire('projekti/novi', 'pages::projects.form')->name('projects.create');
    Route::livewire('projekti/{project}/uredi', 'pages::projects.form')->name('projects.edit');
    Route::livewire('projekti/{project}/dokumenti', 'pages::projects.documents')->name('projects.documents');
    Route::livewire('projekti/{project}/historija', 'pages::projects.history')->name('projects.history');
    Route::get('projekti/{project}/izvjestaj.pdf', [ReportController::class, 'projectPdf'])->name('projects.report.pdf');
    Route::get('projekti/{project}/izvoz.xlsx', [ExportController::class, 'project'])->name('projects.export.xlsx');
    Route::livewire('projekti/{project}', 'pages::projects.show')->name('projects.show');

    // Dokumenti: samo kroz rute s provjerom pristupa (privatni disk).
    Route::get('dokumenti/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('dokumenti/{document}/preuzmi', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('projekti/{project}/dokumenti/{document}/preuzmi', function (Project $project, ProjectDocument $document) {
        abort_unless($document->project_id === $project->id, 404);

        return app(DocumentController::class)->download($document);
    })->name('projects.documents.download');

    // Stavka (detalj) i moje stavke; stare adrese (/zadaci, /moji-zadaci) preusmjeravaju.
    Route::livewire('stavke/{item}', 'pages::tasks.show')->name('tasks.show');
    Route::redirect('zadaci/{item}', '/stavke/{item}');
    Route::livewire('moje-stavke', 'pages::my-tasks.index')->name('my-tasks.index');
    Route::redirect('moji-zadaci', '/moje-stavke');

    // Stari linkovi na predmete (npr. iz ranije poslanih obavještenja) vode u projekat.
    Route::get('predmeti/{case}', function (AdministrativeCase $case) {
        Gate::authorize('view', $case);

        return redirect($case->url());
    })->name('cases.show');

    Route::livewire('obavijesti', 'pages::notifications.index')->name('notifications.index');
    Route::livewire('pomoc', 'pages::help.index')->name('help');
});

require __DIR__.'/settings.php';
