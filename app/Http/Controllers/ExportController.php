<?php

namespace App\Http\Controllers;

use App\Enums\CaseProgressStatus;
use App\Exports\ProjectExport;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Izvoz u Excel (XLSX) uz provjeru pristupa i trenutno aktivne filtere. */
class ExportController extends Controller
{
    public function project(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['all', ...array_column(CaseProgressStatus::cases(), 'value')])]])['status'] ?? 'all';

        $export = new ProjectExport($project, $filter);
        $path = tempnam(sys_get_temp_dir(), 'msp-xlsx-');
        $export->store($path);

        return response()->download($path, $export->filename(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
