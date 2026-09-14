<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicateOrganizationIds = DB::table('classrooms')
            ->whereNotNull('github_organization_id')
            ->select('github_organization_id')
            ->groupBy('github_organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('github_organization_id');

        if ($duplicateOrganizationIds->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce one classroom per GitHub organization. Resolve duplicate organization IDs: '
                .$duplicateOrganizationIds->implode(', '),
            );
        }

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropUnique(['teacher_id']);
            $table->index('teacher_id');
            $table->unique('github_organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('classrooms')
            ->select('teacher_id')
            ->groupBy('teacher_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Cannot restore one-classroom-per-teacher constraint while teachers own multiple classrooms.');
        }

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropUnique(['github_organization_id']);
            $table->dropIndex(['teacher_id']);
            $table->unique('teacher_id');
        });
    }
};
