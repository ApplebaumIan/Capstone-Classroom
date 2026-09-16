<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('github_sync_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_group_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_id');
            $table->string('type');
            $table->string('github_user_id')->nullable();
            $table->string('github_login')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('reconciling_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable();
            $table->timestamps();

            $table->unique(['delivery_id', 'classroom_group_id', 'type']);
            $table->index(['classroom_group_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_sync_issues');
    }
};
