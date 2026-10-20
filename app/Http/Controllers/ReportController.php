<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function projectPdf(Project $project): Response
    {
        Gate::authorize('view', $project);
        $project->load([
            'category', 'responsible',
            'administrativeCases' => fn ($query) => $query->where('is_archived', false),
            'administrativeCases.status', 'administrativeCases.institution',
            'administrativeCases.items' => fn ($query) => $query->where('is_active', true),
            'administrativeCases.items.responsible',
            'documentReferences',
        ]);

        return Pdf::loadView('reports.project-pdf', ['project' => $project])
            ->setPaper('a4')
            ->download('projekat-'.$project->code.'.pdf');
    }
}
