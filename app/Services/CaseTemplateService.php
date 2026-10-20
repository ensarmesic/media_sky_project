<?php

namespace App\Services;

use App\Models\CaseTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CaseTemplateService
{
    /** Kreiranje ili ažuriranje šablona sa stavkama (naziv, tip, težina, obaveznost, redoslijed). */
    public function save(User $user, array $data, array $items, ?CaseTemplate $template = null): CaseTemplate
    {
        return DB::transaction(function () use ($user, $data, $items, $template) {
            $template ??= new CaseTemplate;
            $template->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'case_type_id' => $data['case_type_id'] ?? null,
                'institution_id' => $data['institution_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $template->created_by ?? $user->id,
            ])->save();

            $template->items()->delete();
            foreach (array_values($items) as $index => $item) {
                if (trim((string) ($item['name'] ?? '')) === '') {
                    continue;
                }
                $template->items()->create([
                    'name' => $item['name'],
                    'type' => $item['type'] ?? 'documentation',
                    'progress_weight' => $item['progress_weight'] ?? 1,
                    'is_required_for_closure' => (bool) ($item['is_required_for_closure'] ?? false),
                    'notes' => $item['notes'] ?? null,
                    'position' => $index + 1,
                ]);
            }

            return $template->refresh()->load('items');
        });
    }

    public function delete(CaseTemplate $template): void
    {
        $template->delete();
    }
}
