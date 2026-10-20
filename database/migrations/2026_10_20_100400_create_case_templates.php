<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->foreignId('case_type_id')->nullable()->constrained('case_types')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('case_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('documentation');
            $table->decimal('progress_weight', 8, 2)->unsigned()->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_required_for_closure')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_template_items');
        Schema::dropIfExists('case_templates');
    }
};
