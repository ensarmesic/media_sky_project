<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Stari statusi zadataka (administrativni) → novi opći statusi. */
    private const STATUS_MAP = [
        'not_started' => 'new',
        'in_preparation' => 'in_progress',
        'ready_to_submit' => 'in_progress',
        'supplement_required' => 'in_progress',
        'submitted' => 'waiting',
        'waiting_response' => 'waiting',
        'completed' => 'completed',
        'not_applicable' => 'cancelled',
    ];

    /** Obrnuto mapiranje za rollback (najbliži stari status). */
    private const REVERSE_MAP = [
        'new' => 'not_started',
        'in_progress' => 'in_preparation',
        'waiting' => 'waiting_response',
        'in_review' => 'submitted',
        'completed' => 'completed',
        'cancelled' => 'not_applicable',
    ];

    public function up(): void
    {
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('administrative_case_items')->where('status', $old)->update(['status' => $new]);
        }

        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->string('priority')->default('medium')->after('status')->index();
            $table->index(['administrative_case_id', 'is_active', 'position']);
            $table->index('due_date', 'case_items_due_date_lookup_index');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->morphs('linkable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('url', 2048);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('comments');

        Schema::table('administrative_case_items', function (Blueprint $table) {
            $table->dropIndex('case_items_due_date_lookup_index');
            $table->dropIndex(['administrative_case_id', 'is_active', 'position']);
            $table->dropIndex(['priority']);
            $table->dropColumn('priority');
        });

        foreach (self::REVERSE_MAP as $new => $old) {
            DB::table('administrative_case_items')->where('status', $new)->update(['status' => $old]);
        }
    }
};
