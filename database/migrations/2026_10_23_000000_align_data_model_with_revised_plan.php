<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revidirani plan: administrativni statusi stavki, trenutno stanje projekta,
 * ikona postupka i polimorfni dokumenti (proširenje project_documents).
 */
return new class extends Migration
{
    /** Opći statusi (prethodna migracija) → administrativni statusi stavki. */
    private const STATUS_MAP = [
        'new' => 'not_started',
        'in_progress' => 'in_preparation',
        'waiting' => 'waiting_response',
        'in_review' => 'submitted',
    ];

    public function up(): void
    {
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('administrative_case_items')->where('status', $old)->update(['status' => $new]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('current_state', 500)->nullable()->after('next_step');
        });

        Schema::table('administrative_cases', function (Blueprint $table) {
            $table->string('icon', 50)->nullable()->after('name');
        });

        Schema::table('project_documents', function (Blueprint $table) {
            $table->nullableMorphs('documentable');
            $table->string('original_name')->nullable()->after('name');
            $table->string('direction', 20)->nullable()->after('document_type')->index();
            $table->string('protocol_number', 100)->nullable()->after('direction')->index();
            $table->date('document_date')->nullable()->after('protocol_number');
        });

        // Postojeći dokumenti dobijaju vlasnika: stavka, postupak ili projekat.
        DB::table('project_documents')->orderBy('id')->each(function (object $document) {
            [$type, $id] = match (true) {
                $document->administrative_case_item_id !== null => ['App\Models\AdministrativeCaseItem', $document->administrative_case_item_id],
                $document->administrative_case_id !== null => ['App\Models\AdministrativeCase', $document->administrative_case_id],
                default => ['App\Models\Project', $document->project_id],
            };
            DB::table('project_documents')->where('id', $document->id)->update([
                'documentable_type' => $type,
                'documentable_id' => $id,
                'original_name' => $document->name,
            ]);
        });

        // Prazna tabela iz ove nadogradnje — dokumenti idu u project_documents (odobreno).
        Schema::dropIfExists('attachments');
    }

    public function down(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropMorphs('documentable');
            $table->dropIndex(['direction']);
            $table->dropIndex(['protocol_number']);
            $table->dropColumn(['original_name', 'direction', 'protocol_number', 'document_date']);
        });
        Schema::table('administrative_cases', fn (Blueprint $table) => $table->dropColumn('icon'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('current_state'));

        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('administrative_case_items')->where('status', $new)->update(['status' => $old]);
        }
    }
};
