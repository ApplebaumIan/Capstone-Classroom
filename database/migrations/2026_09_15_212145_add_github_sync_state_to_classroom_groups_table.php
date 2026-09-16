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
        Schema::table('classroom_groups', function (Blueprint $table) {
            $table->string('github_team_name')->nullable()->after('github_team_id');
            $table->boolean('github_team_repository_access')->nullable()->after('github_team_url');
            $table->timestamp('github_team_missing_at')->nullable()->after('github_team_repository_access');
            $table->timestamp('github_repository_missing_at')->nullable()->after('github_pages_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classroom_groups', function (Blueprint $table) {
            $table->dropColumn([
                'github_team_name',
                'github_team_repository_access',
                'github_team_missing_at',
                'github_repository_missing_at',
            ]);
        });
    }
};
