<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reference_items', function (Blueprint $table) {
            $table->foreignId('document_reference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('administrative_case_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_reference_id', 'administrative_case_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reference_items');
    }
};
