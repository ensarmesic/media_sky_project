<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('responsible_user_id')->constrained()->nullOnDelete();
            $table->string('recipient')->nullable()->after('institution_id');
            $table->date('submitted_at')->nullable()->after('start_date');
            $table->date('response_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
            $table->dropColumn(['recipient', 'submitted_at', 'response_at']);
        });
    }
};
