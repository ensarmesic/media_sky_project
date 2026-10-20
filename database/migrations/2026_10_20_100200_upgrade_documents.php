<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->foreignId('administrative_case_id')->nullable()->after('project_id')->constrained('administrative_cases')->nullOnDelete();
            $table->foreignId('administrative_case_item_id')->nullable()->after('administrative_case_id')->constrained('administrative_case_items')->nullOnDelete();
            $table->string('document_type')->nullable()->after('description')->index();
            $table->unsignedInteger('version')->default(1)->after('document_type');
            $table->foreignId('replaced_by_id')->nullable()->after('version')->constrained('project_documents')->nullOnDelete();
            $table->boolean('is_archived')->default(false)->after('replaced_by_id')->index();
        });

        Schema::table('document_references', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('direction');
            $table->string('source_app')->nullable()->after('url');
            $table->text('description')->nullable()->after('document_date');
            $table->unsignedInteger('version')->default(1)->after('description');
            $table->foreignId('replaced_by_id')->nullable()->after('version')->constrained('document_references')->nullOnDelete();
            $table->boolean('is_archived')->default(false)->after('replaced_by_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_references', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropColumn(['url', 'source_app', 'description', 'version', 'is_archived']);
        });
        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropConstrainedForeignId('administrative_case_item_id');
            $table->dropConstrainedForeignId('administrative_case_id');
            $table->dropColumn(['document_type', 'version', 'is_archived']);
        });
    }
};
