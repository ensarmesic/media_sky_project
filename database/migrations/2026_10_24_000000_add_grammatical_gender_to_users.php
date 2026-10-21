<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Oblik obraćanja (m/f) za rečenice u historiji: "je promijenio" / "je promijenila". */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('grammatical_gender', 1)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('grammatical_gender');
        });
    }
};
