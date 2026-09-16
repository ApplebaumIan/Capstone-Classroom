import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Plus,
    RefreshCw,
    Settings2,
} from 'lucide-react';
import DeleteClassroomDialog from '@/components/delete-classroom-dialog';
import GitHubAvatar from '@/components/github-avatar';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { update as updateTeamCreation } from '@/routes/classroom-team-creation';
import { store as createClassroomGroup } from '@/routes/classroom-groups';
import { store as acceptGitHubSyncIssue } from '@/routes/github-sync-issue-acceptances';
import { store as resynchronizeGitHubSyncIssue } from '@/routes/github-sync-issue-resynchronizations';
import { store as retryProvisioning } from '@/routes/group-provisioning';

type SyncIssue = {
    id: number;
    type: 'membership_added' | 'membership_removed';
    github_login: string | null;
    can_accept: boolean;
    error: string | null;
};

type Group = {
    id: number;
    name: string;
    status: 'waiting' | 'provisioning' | 'ready' | 'failed' | 'missing';
    error: string | null;
    team_name: string | null;
    team_url: string | null;
    team_missing: boolean;
    team_repository_access: boolean | null;
    repository_url: string | null;
    repository_missing: boolean;
    pages_url: string | null;
    sync_issues: SyncIssue[];
    is_testing: boolean;
    students: Array<{
        id: number;
        name: string;
        github_login: string | null;
        avatar_url: string | null;
        claimed: boolean;
    }>;
};

type Props = {
    classroom: {
        id: number;
        name: string;
        organization: string | null;
        installed: boolean;
        installation_status: 'active' | 'suspended' | 'deleted' | null;
        student_team_creation_enabled: boolean;
        groups: Group[];
    };
};

function StatusBadge({ status }: { status: Group['status'] }) {
    const labels = {
        waiting: 'Waiting',
        provisioning: 'Provisioning',
        ready: 'Ready',
        failed: 'Needs attention',
        missing: 'Missing on GitHub',
    };

    return (
        <Badge
            variant={
                status === 'failed' || status === 'missing'
                    ? 'destructive'
                    : status === 'ready'
                      ? 'default'
                      : 'secondary'
            }
        >
            {status === 'provisioning' && (
                <RefreshCw className="animate-spin" />
            )}
            {status === 'ready' && <CheckCircle2 />}
            {labels[status]}
        </Badge>
    );
}

export default function Teams({ classroom }: Props) {
    const { errors, flash } = usePage().props;

    usePoll(10_000, { only: ['classroom'] });

    return (
        <>
            <Head title={`${classroom.name} Teams`} />
            <div className="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="space-y-2">
                        <Badge variant="outline">Teams</Badge>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {classroom.name}
                        </h1>
                        <p className="text-muted-foreground">
                            {classroom.organization
                                ? `GitHub organization: ${classroom.organization}`
                                : 'GitHub organization not connected'}
                        </p>
                    </div>
                    <DeleteClassroomDialog classroom={classroom} />
                </div>

                {flash.success && (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Complete</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {errors.github_sync && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>Unable to apply GitHub change</AlertTitle>
                        <AlertDescription>
                            {errors.github_sync}
                        </AlertDescription>
                    </Alert>
                )}

                {!classroom.installed && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>GitHub App is not active</AlertTitle>
                        <AlertDescription>
                            {classroom.installation_status === 'suspended'
                                ? 'The organization suspended this GitHub App installation. Unsuspend it on GitHub before making changes.'
                                : 'The GitHub App installation is missing or was removed. Reconnect it before making changes.'}
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="space-y-1">
                                <CardTitle>Create a team</CardTitle>
                                <CardDescription>
                                    Add project team without Canvas import.
                                </CardDescription>
                            </div>
                            <Badge variant="secondary">
                                Student creation{' '}
                                {classroom.student_team_creation_enabled
                                    ? 'enabled'
                                    : 'disabled'}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                        <Form
                            {...createClassroomGroup.form(classroom.id)}
                            className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="team-name">
                                            Team name
                                        </Label>
                                        <Input
                                            id="team-name"
                                            name="name"
                                            placeholder="Project Atlas"
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <Button
                                        disabled={
                                            processing || !classroom.installed
                                        }
                                    >
                                        <Plus /> Create team
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Form {...updateTeamCreation.form(classroom.id)}>
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="enabled"
                                        value={
                                            classroom.student_team_creation_enabled
                                                ? '0'
                                                : '1'
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        disabled={
                                            processing || !classroom.installed
                                        }
                                    >
                                        <Settings2 />
                                        {classroom.student_team_creation_enabled
                                            ? 'Disable student creation'
                                            : 'Enable student creation'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {classroom.groups.length === 0 ? (
                    <Card className="border-dashed">
                        <CardHeader>
                            <CardTitle>No teams yet</CardTitle>
                            <CardDescription>
                                Import Canvas roster or create first team.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {classroom.groups.map((group) => (
                            <Card key={group.id} className="h-full">
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <CardTitle>
                                                    {group.name}
                                                </CardTitle>
                                                {group.is_testing && (
                                                    <Badge variant="outline">
                                                        Testing
                                                    </Badge>
                                                )}
                                            </div>
                                            <CardDescription>
                                                {
                                                    group.students.filter(
                                                        (student) =>
                                                            student.claimed,
                                                    ).length
                                                }{' '}
                                                of {group.students.length}{' '}
                                                students joined
                                            </CardDescription>
                                        </div>
                                        <StatusBadge status={group.status} />
                                    </div>
                                </CardHeader>
                                <CardContent className="grid gap-5">
                                    {(group.repository_missing ||
                                        group.team_missing ||
                                        group.team_repository_access ===
                                            false) && (
                                        <Alert variant="destructive">
                                            <AlertTriangle />
                                            <AlertTitle>
                                                {group.repository_missing
                                                    ? 'Repository deleted on GitHub'
                                                    : group.team_missing
                                                      ? 'GitHub team deleted'
                                                      : 'Repository access removed'}
                                            </AlertTitle>
                                            <AlertDescription>
                                                <p>
                                                    {group.repository_missing
                                                        ? 'The classroom record and team are intact. Recreate the repository when ready.'
                                                        : group.team_missing
                                                          ? 'The classroom record, repository, and roster are intact. Recreate the team when ready.'
                                                          : 'The GitHub team no longer has access to its repository.'}
                                                </p>
                                                <Form
                                                    {...retryProvisioning.form([
                                                        classroom.id,
                                                        group.id,
                                                    ])}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                processing ||
                                                                !classroom.installed
                                                            }
                                                        >
                                                            <RefreshCw />
                                                            {group.repository_missing
                                                                ? 'Recreate repository'
                                                                : group.team_missing
                                                                  ? 'Recreate team'
                                                                  : 'Restore access'}
                                                        </Button>
                                                    )}
                                                </Form>
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {group.team_url && (
                                            <a
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                                href={group.team_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Team <ExternalLink />
                                            </a>
                                        )}
                                        {group.repository_url && (
                                            <a
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                                href={group.repository_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Repository <ExternalLink />
                                            </a>
                                        )}
                                        {group.pages_url && (
                                            <a
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                                href={group.pages_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Pages <ExternalLink />
                                            </a>
                                        )}
                                        {group.status === 'failed' && (
                                            <Form
                                                {...retryProvisioning.form([
                                                    classroom.id,
                                                    group.id,
                                                ])}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        disabled={
                                                            processing ||
                                                            !classroom.installed
                                                        }
                                                    >
                                                        <RefreshCw /> Retry
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                    {group.team_name &&
                                        group.team_name !== group.name && (
                                            <p className="text-muted-foreground text-sm">
                                                GitHub team name:{' '}
                                                <span className="text-foreground font-medium">
                                                    {group.team_name}
                                                </span>
                                            </p>
                                        )}
                                    {group.error && (
                                        <p className="text-destructive text-sm">
                                            {group.error}
                                        </p>
                                    )}
                                    {group.sync_issues.map((issue) => (
                                        <Alert key={issue.id}>
                                            <AlertTriangle />
                                            <AlertTitle>
                                                GitHub membership changed
                                            </AlertTitle>
                                            <AlertDescription>
                                                <p>
                                                    @
                                                    {issue.github_login ??
                                                        'unknown'}{' '}
                                                    was{' '}
                                                    {issue.type ===
                                                    'membership_added'
                                                        ? 'added to'
                                                        : 'removed from'}{' '}
                                                    this team directly on
                                                    GitHub.
                                                </p>
                                                {issue.type ===
                                                    'membership_removed' && (
                                                    <p>
                                                        Accepting moves the
                                                        student to the
                                                        pending-student list.
                                                    </p>
                                                )}
                                                {issue.error && (
                                                    <p className="text-destructive">
                                                        Last resync failed:{' '}
                                                        {issue.error}
                                                    </p>
                                                )}
                                                <div className="flex flex-wrap gap-2 pt-1">
                                                    {issue.can_accept && (
                                                        <Form
                                                            {...acceptGitHubSyncIssue.form(
                                                                [
                                                                    classroom.id,
                                                                    group.id,
                                                                    issue.id,
                                                                ],
                                                            )}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        processing ||
                                                                        !classroom.installed
                                                                    }
                                                                >
                                                                    Accept
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                    <Form
                                                        {...resynchronizeGitHubSyncIssue.form(
                                                            [
                                                                classroom.id,
                                                                group.id,
                                                                issue.id,
                                                            ],
                                                        )}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                size="sm"
                                                                disabled={
                                                                    processing ||
                                                                    !classroom.installed
                                                                }
                                                            >
                                                                <RefreshCw />{' '}
                                                                Resync GitHub
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            </AlertDescription>
                                        </Alert>
                                    ))}
                                    <div className="divide-y rounded-lg border">
                                        {group.students.map((student) => (
                                            <div
                                                key={student.id}
                                                className="px-4 py-3"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <GitHubAvatar
                                                        name={student.name}
                                                        avatarUrl={
                                                            student.avatar_url
                                                        }
                                                    />
                                                    <div>
                                                        <p className="font-medium">
                                                            {student.name}
                                                        </p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {student.github_login
                                                                ? `@${student.github_login}`
                                                                : 'Not joined'}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Teams.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Teams', href: '#' },
    ],
};
