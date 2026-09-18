<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $github_id
 * @property string|null $github_login
 * @property string|null $avatar_url
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $teacher_access_requested_at
 * @property Carbon|null $teacher_access_approved_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Classroom> $classrooms
 * @property-read Collection<int, Classroom> $pendingClassrooms
 */
#[Fillable(['github_id', 'github_login', 'avatar_url', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @return HasMany<Classroom, $this> */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    /** @return HasMany<RosterEntry, $this> */
    public function rosterClaims(): HasMany
    {
        return $this->hasMany(RosterEntry::class, 'claimed_by_user_id');
    }

    /** @return BelongsToMany<Classroom, $this> */
    public function pendingClassrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'pending_classroom_students')->withTimestamps();
    }

    public function isStudentOnly(): bool
    {
        return ! $this->classrooms()->exists()
            && ($this->rosterClaims()->exists() || $this->pendingClassrooms()->exists());
    }

    public function hasTeacherAccess(): bool
    {
        return $this->teacher_access_approved_at !== null || $this->classrooms()->exists();
    }

    public function canCreateClassrooms(): bool
    {
        return $this->hasTeacherAccess() && ! $this->isStudentOnly();
    }

    public function canRequestTeacherAccess(): bool
    {
        return ! $this->hasTeacherAccess() && ! $this->isStudentOnly();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'teacher_access_requested_at' => 'datetime',
            'teacher_access_approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
