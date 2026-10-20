<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_cases', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('progress_weight');
            $table->boolean('is_archived')->default(false)->after('position')->index();
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('response_reminder_interval')->nullable()->after('response_at');
            $table->timestamp('response_reminded_at')->nullable()->after('response_reminder_interval');
            $table->boolean('is_blocked')->default(false)->after('response_reminded_at');
            $table->string('blocked_reason', 500)->nullable()->after('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->dropColumn(['response_reminder_interval', 'response_reminded_at', 'is_blocked', 'blocked_reason']);
        });
        Schema::table('administrative_cases', function (Blueprint $table) {
            $table->dropColumn(['position', 'is_archived', 'archived_at']);
        });
    }
};
