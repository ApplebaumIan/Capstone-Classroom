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

/**
 * @property int $id
 * @property int $classroom_id
 * @property string $name
 * @property string $repository_name
 * @property GroupStatus $status
 * @property string|null $github_team_id
 * @property string|null $github_team_slug
 * @property string|null $github_team_url
 * @property string|null $github_repository_id
 * @property string|null $github_repository_url
 * @property string|null $github_pages_url
 * @property string|null $provisioning_error
 * @property-read Classroom $classroom
 * @property-read Collection<int, RosterEntry> $rosterEntries
 */
#[Fillable(['classroom_id', 'name', 'canvas_group_id', 'canvas_group_reference', 'repository_name', 'status', 'github_team_id', 'github_team_slug', 'github_team_url', 'github_repository_id', 'github_repository_url', 'github_pages_url', 'provisioning_error'])]
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

    protected function casts(): array
    {
        return ['status' => GroupStatus::class];
    }
}
