<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('administrative_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_system');
            $table->string('external_document_id');
            $table->string('protocol_number')->nullable()->index();
            $table->string('title')->index();
            $table->string('document_type')->nullable()->index();
            $table->string('direction')->index();
            $table->date('document_date')->nullable()->index();
            $table->date('transferred_at')->nullable();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sync_status')->default('pending')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['external_system', 'external_document_id']);
            $table->index(['project_id', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_references');
    }
};
