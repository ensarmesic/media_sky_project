<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('type')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('case_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('system_key')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('case_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('system_key')->unique();
            $table->string('color')->default('zinc');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('administrative_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->foreignId('case_type_id')->constrained('case_types');
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_user_id')->constrained('users');
            $table->date('started_at');
            $table->date('submitted_at')->nullable();
            $table->date('response_due_at')->nullable()->index();
            $table->foreignId('case_status_id')->constrained('case_statuses');
            $table->string('outcome')->nullable();
            $table->text('notes')->nullable();
            $table->date('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('case_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrative_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('event_type')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_activities');
        Schema::dropIfExists('administrative_cases');
        Schema::dropIfExists('case_statuses');
        Schema::dropIfExists('case_types');
        Schema::dropIfExists('institutions');
    }
};
