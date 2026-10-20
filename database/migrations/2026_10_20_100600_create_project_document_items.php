<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_document_items', function (Blueprint $table) {
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('administrative_case_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_document_id', 'administrative_case_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_document_items');
    }
};
