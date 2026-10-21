<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Indeksi za česte upite: postupci projekta, moji projekti, historija po korisniku, nepročitane obavijesti. */
    private const INDEXES = [
        'administrative_cases' => [['project_id', 'is_archived', 'position']],
        'administrative_case_items' => [['status', 'due_date']],
        'projects' => [['responsible_user_id']],
        'project_user' => [['user_id']],
        'project_activities' => [['project_id', 'user_id']],
        'notifications' => [['notifiable_type', 'notifiable_id', 'read_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($columns));
                }
            }
        }
    }
};
