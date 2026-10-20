<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_cases', function (Blueprint $table) {
            $table->decimal('progress_weight', 8, 2)->unsigned()->default(1);
        });
        Schema::create('administrative_case_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrative_case_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('other');
            $table->string('status')->default('not_started')->index();
            $table->string('outcome')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('progress_weight', 8, 2)->unsigned()->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_required_for_closure')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('administrative_case_item_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrative_case_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('administrative_case_item_task', function (Blueprint $table) {
            $table->foreignId('administrative_case_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['administrative_case_item_id', 'task_id']);
        });
        Schema::table('document_references', function (Blueprint $table) {
            $table->foreignId('administrative_case_item_id')->nullable()->after('administrative_case_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_references', fn (Blueprint $table) => $table->dropConstrainedForeignId('administrative_case_item_id'));
        Schema::dropIfExists('administrative_case_item_task');
        Schema::dropIfExists('administrative_case_item_activities');
        Schema::dropIfExists('administrative_case_items');
        Schema::table('administrative_cases', fn (Blueprint $table) => $table->dropColumn('progress_weight'));
    }
};
