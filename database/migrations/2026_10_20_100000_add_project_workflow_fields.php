<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('next_step')->nullable()->after('notes');
            $table->text('completion_note')->nullable()->after('next_step');
            $table->date('completed_at')->nullable()->after('completion_note');
            $table->timestamp('archived_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['next_step', 'completion_note', 'completed_at', 'archived_at']);
        });
    }
};
