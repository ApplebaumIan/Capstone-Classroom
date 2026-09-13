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
        Schema::create('classroom_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('canvas_group_id')->nullable();
            $table->string('canvas_group_reference')->nullable();
            $table->string('repository_name');
            $table->string('status')->default('waiting');
            $table->string('github_team_id')->nullable();
            $table->string('github_team_slug')->nullable();
            $table->string('github_team_url')->nullable();
            $table->string('github_repository_id')->nullable();
            $table->string('github_repository_url')->nullable();
            $table->string('github_pages_url')->nullable();
            $table->text('provisioning_error')->nullable();
            $table->timestamps();

            $table->unique(['classroom_id', 'name']);
            $table->unique(['classroom_id', 'repository_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classroom_groups');
    }
};
