<?php

namespace App\Models;

use App\RepositoryVisibility;
use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $teacher_id
 * @property string $name
 * @property string $join_code
 * @property RepositoryVisibility $repository_visibility
 * @property string|null $github_organization_id
 * @property string|null $github_organization_login
 * @property string|null $github_installation_id
 * @property Carbon|null $roster_imported_at
 * @property Carbon|null $roster_skipped_at
 * @property bool $student_team_creation_enabled
 * @property-read int|null $student_count
 * @property-read int|null $claimed_students_count
 * @property-read int|null $groups_count
 * @property-read User $teacher
 * @property-read Collection<int, ClassroomGroup> $groups
 * @property-read Collection<int, RosterEntry> $rosterEntries
 * @property-read Collection<int, User> $pendingStudents
 */
#[Fillable(['teacher_id', 'name', 'join_code', 'repository_visibility', 'github_organization_id', 'github_organization_login', 'github_installation_id', 'roster_imported_at', 'roster_skipped_at', 'student_team_creation_enabled'])]
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** @return HasMany<ClassroomGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(ClassroomGroup::class);
    }

    /** @return HasMany<RosterEntry, $this> */
    public function rosterEntries(): HasMany
    {
        return $this->hasMany(RosterEntry::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function pendingStudents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pending_classroom_students')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'repository_visibility' => RepositoryVisibility::class,
            'roster_imported_at' => 'datetime',
            'roster_skipped_at' => 'datetime',
            'student_team_creation_enabled' => 'boolean',
        ];
    }
}
