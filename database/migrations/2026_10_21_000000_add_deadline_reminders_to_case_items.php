<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->timestamp('deadline_reminded_at')->nullable()->after('response_reminded_at');
            $table->timestamp('overdue_reminded_at')->nullable()->after('deadline_reminded_at');
            $table->index(['responsible_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->dropIndex(['responsible_user_id', 'status']);
            $table->dropColumn(['deadline_reminded_at', 'overdue_reminded_at']);
        });
    }
};
