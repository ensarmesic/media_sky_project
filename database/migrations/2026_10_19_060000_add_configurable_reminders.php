<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_days_before')->nullable()->default(3);
            $table->timestamp('overdue_reminded_at')->nullable()->index();
        });
        Schema::table('administrative_cases', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_days_before')->nullable()->default(3);
            $table->timestamp('deadline_reminded_at')->nullable()->index();
            $table->timestamp('overdue_reminded_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['reminder_days_before', 'overdue_reminded_at']));
        Schema::table('administrative_cases', fn (Blueprint $table) => $table->dropColumn(['reminder_days_before', 'deadline_reminded_at', 'overdue_reminded_at']));
    }
};
