<?php

namespace Database\Seeders;

use App\GroupStatus;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use App\RepositoryVisibility;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocalDevelopmentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teacher = $this->user('local-teacher', 'Local Teacher', 'teacher@capstone.local');
        $student = $this->user('local-student', 'Local Student', 'student@capstone.local');
        $jordan = $this->user('jordan-lee', 'Jordan Lee', 'jordan@capstone.local');
        $taylor = $this->user('taylor-morgan', 'Taylor Morgan', 'taylor@capstone.local');

        $classroom = Classroom::query()->updateOrCreate(
            ['teacher_id' => $teacher->id],
            [
                'name' => 'CIS 4398 Capstone',
                'join_code' => 'local-capstone-classroom',
                'repository_visibility' => RepositoryVisibility::Private,
                'github_organization_id' => 'local-capstone-org',
                'github_organization_login' => 'temple-capstone',
                'github_installation_id' => 'local-installation',
                'roster_imported_at' => now(),
            ],
        );

        $readyGroup = $this->group($classroom, 'Local Demo Team', 'local-demo-team', GroupStatus::Ready, [
            'github_team_id' => 'local-team-1',
            'github_team_slug' => 'local-demo-team',
            'github_team_url' => 'https://github.com/orgs/temple-capstone/teams/local-demo-team',
            'github_repository_id' => 'local-repository-1',
            'github_repository_url' => 'https://github.com/temple-capstone/local-demo-team',
            'github_pages_url' => 'https://temple-capstone.github.io/local-demo-team',
        ]);
        $provisioningGroup = $this->group($classroom, 'Accessibility Lab', 'accessibility-lab', GroupStatus::Provisioning);
        $failedGroup = $this->group($classroom, 'Campus Navigator', 'campus-navigator', GroupStatus::Failed, [
            'provisioning_error' => 'GitHub repository creation timed out. Retry provisioning to test recovery.',
        ]);

        $this->rosterEntry($classroom, $readyGroup, 'local-student', 'Local Student', $student);
        $this->rosterEntry($classroom, $readyGroup, 'jordan-lee', 'Jordan Lee', $jordan);
        $this->rosterEntry($classroom, $readyGroup, 'morgan-patel', 'Morgan Patel');
        $this->rosterEntry($classroom, $provisioningGroup, 'taylor-morgan', 'Taylor Morgan', $taylor);
        $this->rosterEntry($classroom, $provisioningGroup, 'casey-nguyen', 'Casey Nguyen');
        $this->rosterEntry($classroom, $failedGroup, 'riley-garcia', 'Riley Garcia');
    }

    private function user(string $githubLogin, string $name, string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'github_id' => $githubLogin,
            'github_login' => $githubLogin,
            'name' => $name,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => $user->password ?: Str::password(32),
        ])->save();

        return $user;
    }

    /** @param array<string, string> $attributes */
    private function group(
        Classroom $classroom,
        string $name,
        string $repositoryName,
        GroupStatus $status,
        array $attributes = [],
    ): ClassroomGroup {
        return ClassroomGroup::query()->updateOrCreate(
            [
                'classroom_id' => $classroom->id,
                'repository_name' => $repositoryName,
            ],
            [
                'name' => $name,
                'canvas_group_id' => $repositoryName,
                'canvas_group_reference' => $repositoryName,
                'status' => $status,
                ...$attributes,
            ],
        );
    }

    private function rosterEntry(
        Classroom $classroom,
        ClassroomGroup $group,
        string $canvasUserId,
        string $name,
        ?User $student = null,
    ): void {
        RosterEntry::query()->updateOrCreate(
            [
                'classroom_id' => $classroom->id,
                'canvas_user_id' => $canvasUserId,
            ],
            [
                'classroom_group_id' => $group->id,
                'claimed_by_user_id' => $student?->id,
                'canvas_login_id' => $canvasUserId,
                'canvas_id' => $canvasUserId,
                'name' => $name,
                'sections' => '2026 Fall - CIS-4398-001',
                'claimed_at' => $student === null ? null : now(),
            ],
        );
    }
}
