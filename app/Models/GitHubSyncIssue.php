<?php

namespace App\Models;

use App\Enums\GitHubSyncIssueType;
use App\Enums\GitHubSyncResolution;
use Database\Factories\GitHubSyncIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $classroom_group_id
 * @property string $delivery_id
 * @property GitHubSyncIssueType $type
 * @property string|null $github_user_id
 * @property string|null $github_login
 * @property array<string, mixed>|null $metadata
 * @property Carbon $detected_at
 * @property Carbon|null $reconciling_at
 * @property Carbon|null $resolved_at
 * @property GitHubSyncResolution|null $resolution
 * @property-read ClassroomGroup $classroomGroup
 */
#[Fillable(['classroom_group_id', 'delivery_id', 'type', 'github_user_id', 'github_login', 'metadata', 'detected_at', 'reconciling_at', 'resolved_at', 'resolution'])]
class GitHubSyncIssue extends Model
{
    /** @use HasFactory<GitHubSyncIssueFactory> */
    use HasFactory;

    protected $table = 'github_sync_issues';

    /** @return BelongsTo<ClassroomGroup, $this> */
    public function classroomGroup(): BelongsTo
    {
        return $this->belongsTo(ClassroomGroup::class);
    }

    protected function casts(): array
    {
        return [
            'type' => GitHubSyncIssueType::class,
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'reconciling_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution' => GitHubSyncResolution::class,
        ];
    }
}
