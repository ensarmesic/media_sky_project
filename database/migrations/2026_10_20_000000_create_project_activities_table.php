<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->nullableMorphs('subject');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });

        DB::table('projects')->orderBy('id')->get()->each(function ($project) {
            DB::table('project_activities')->insert([
                'project_id' => $project->id, 'user_id' => $project->created_by,
                'event_type' => 'project_created', 'title' => 'Projekat kreiran',
                'created_at' => $project->created_at, 'updated_at' => $project->created_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
