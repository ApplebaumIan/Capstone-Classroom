<?php

namespace App\Models;

use App\GroupStatus;
use Database\Factories\ClassroomGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $classroom_id
 * @property string $name
 * @property string $repository_name
 * @property GroupStatus $status
 * @property bool $created_manually
 * @property string|null $github_team_id
 * @property string|null $github_team_name
 * @property string|null $github_team_slug
 * @property string|null $github_team_url
 * @property bool|null $github_team_repository_access
 * @property Carbon|null $github_team_missing_at
 * @property string|null $github_repository_id
 * @property string|null $github_repository_url
 * @property string|null $github_pages_url
 * @property Carbon|null $github_repository_missing_at
 * @property string|null $provisioning_error
 * @property-read Classroom $classroom
 * @property-read Collection<int, RosterEntry> $rosterEntries
 * @property-read Collection<int, GitHubSyncIssue> $syncIssues
 */
#[Fillable(['classroom_id', 'name', 'canvas_group_id', 'canvas_group_reference', 'repository_name', 'status', 'created_manually', 'github_team_id', 'github_team_name', 'github_team_slug', 'github_team_url', 'github_team_repository_access', 'github_team_missing_at', 'github_repository_id', 'github_repository_url', 'github_pages_url', 'github_repository_missing_at', 'provisioning_error'])]
class ClassroomGroup extends Model
{
    /** @use HasFactory<ClassroomGroupFactory> */
    use HasFactory;

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return HasMany<RosterEntry, $this> */
    public function rosterEntries(): HasMany
    {
        return $this->hasMany(RosterEntry::class);
    }

    /** @return HasMany<GitHubSyncIssue, $this> */
    public function syncIssues(): HasMany
    {
        return $this->hasMany(GitHubSyncIssue::class);
    }

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'created_manually' => 'boolean',
            'github_team_repository_access' => 'boolean',
            'github_team_missing_at' => 'datetime',
            'github_repository_missing_at' => 'datetime',
        ];
    }
}
