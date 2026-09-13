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
        Schema::create('roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('canvas_user_id');
            $table->string('canvas_login_id');
            $table->string('canvas_id');
            $table->string('name');
            $table->string('sections');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['classroom_id', 'canvas_user_id']);
            $table->unique(['classroom_id', 'claimed_by_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roster_entries');
    }
};
