<?php

namespace Database\Seeders;

use App\Enums\GroupStatus;
use App\Enums\RepositoryVisibility;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
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
        $teacher = $this->user('local-teacher', 'Demo Teacher', 'teacher@capstone.local', 'https://avatars.githubusercontent.com/u/9919?v=4');
        $student = $this->user('local-student', 'Demo Student', 'student@capstone.local', 'https://avatars.githubusercontent.com/u/41898282?v=4');
        $exampleStudentA = $this->user('example-student-a', 'Example Student A', 'student-a@capstone.local', 'https://avatars.githubusercontent.com/u/49699333?v=4');
        $exampleStudentB = $this->user('example-student-b', 'Example Student B', 'student-b@capstone.local', 'https://avatars.githubusercontent.com/u/19864447?v=4');
        $pendingStudent = $this->user('pending-student', 'Pending Student', 'pending-student@capstone.local', 'https://avatars.githubusercontent.com/u/9919?v=4');

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

        $classroom->pendingStudents()->detach();
        $classroom->rosterEntries()->delete();
        $classroom->groups()->delete();

        $readyGroup = $this->group($classroom, 'Local Demo Team', 'local-demo-team', GroupStatus::Ready, [
            'github_team_id' => 'local-team-1',
            'github_team_slug' => 'local-demo-team',
            'github_team_url' => 'https://github.com/orgs/temple-capstone/teams/local-demo-team',
            'github_repository_id' => 'local-repository-1',
            'github_repository_url' => 'https://github.com/temple-capstone/local-demo-team',
            'github_pages_url' => 'https://temple-capstone.github.io/local-demo-team',
            'created_manually' => true,
        ]);
        $provisioningGroup = $this->group($classroom, 'Accessibility Lab', 'accessibility-lab', GroupStatus::Provisioning);
        $failedGroup = $this->group($classroom, 'Campus Navigator', 'campus-navigator', GroupStatus::Failed, [
            'provisioning_error' => 'GitHub repository creation timed out. Retry provisioning to test recovery.',
        ]);

        $this->rosterEntry($classroom, $readyGroup, 'local-student', 'Demo Student', $student);
        $this->rosterEntry($classroom, $readyGroup, 'example-student-a', 'Example Student A', $exampleStudentA);
        $this->rosterEntry($classroom, $readyGroup, 'sample-student-a', 'Sample Student A');
        $this->rosterEntry($classroom, $provisioningGroup, 'example-student-b', 'Example Student B', $exampleStudentB);
        $this->rosterEntry($classroom, $provisioningGroup, 'sample-student-b', 'Sample Student B');
        $this->rosterEntry($classroom, $failedGroup, 'sample-student-c', 'Sample Student C');
        $classroom->rosterEntries()->where('claimed_by_user_id', $pendingStudent->id)->delete();
        $classroom->pendingStudents()->syncWithoutDetaching([$pendingStudent->id]);
    }

    private function user(string $githubLogin, string $name, string $email, string $avatarUrl): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'github_id' => $githubLogin,
            'github_login' => $githubLogin,
            'avatar_url' => $avatarUrl,
            'name' => $name,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'teacher_access_approved_at' => $user->teacher_access_approved_at ?? now(),
            'password' => $user->password ?: Str::password(32),
        ])->save();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
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
