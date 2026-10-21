<?php

namespace App\Http\Controllers;

use App\Enums\CaseProgressStatus;
use App\Exports\InstitutionExport;
use App\Exports\ProjectExport;
use App\Models\Institution;
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

        return $this->download($path, $export->filename());
    }

    public function institution(Request $request, Institution $institution): BinaryFileResponse
    {
        Gate::authorize('view', $institution);

        $export = new InstitutionExport($institution, $request->user());
        $path = tempnam(sys_get_temp_dir(), 'msp-xlsx-');
        $export->store($path);

        return $this->download($path, $export->filename());
    }

    private function download(string $path, string $filename): BinaryFileResponse
    {
        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
