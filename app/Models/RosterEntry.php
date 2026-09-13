<?php

namespace App\Models;

use Database\Factories\RosterEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $classroom_id
 * @property int $classroom_group_id
 * @property int|null $claimed_by_user_id
 * @property string $canvas_user_id
 * @property string $canvas_login_id
 * @property string $canvas_id
 * @property string $name
 * @property string $sections
 * @property Carbon|null $claimed_at
 * @property-read Classroom $classroom
 * @property-read ClassroomGroup $group
 * @property-read User|null $claimedBy
 */
#[Fillable(['classroom_id', 'classroom_group_id', 'claimed_by_user_id', 'canvas_user_id', 'canvas_login_id', 'canvas_id', 'name', 'sections', 'claimed_at'])]
class RosterEntry extends Model
{
    /** @use HasFactory<RosterEntryFactory> */
    use HasFactory;

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<ClassroomGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassroomGroup::class, 'classroom_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }
}
