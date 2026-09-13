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
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name')->default('CIS 4398 Capstone');
            $table->string('join_code', 40)->unique();
            $table->string('repository_visibility')->default('private');
            $table->string('github_organization_id')->nullable();
            $table->string('github_organization_login')->nullable();
            $table->string('github_installation_id')->nullable()->unique();
            $table->timestamp('roster_imported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
