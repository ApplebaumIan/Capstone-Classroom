import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    Plus,
    RefreshCw,
    Settings2,
} from 'lucide-react';
import { useEffect } from 'react';
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
import { store as retryProvisioning } from '@/routes/group-provisioning';

type Group = {
    id: number;
    name: string;
    status: 'waiting' | 'provisioning' | 'ready' | 'failed';
    error: string | null;
    team_url: string | null;
    repository_url: string | null;
    pages_url: string | null;
    is_testing: boolean;
    students: Array<{
        id: number;
        name: string;
        github_login: string | null;
        claimed: boolean;
    }>;
};

type Props = {
    classroom: {
        id: number;
        name: string;
        organization: string | null;
        installed: boolean;
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
    };

    return (
        <Badge
            variant={
                status === 'failed'
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
    const { flash } = usePage().props;
    const shouldPoll = classroom.groups.some(
        (group) => group.status === 'provisioning',
    );

    const { stop } = usePoll(
        10_000,
        { only: ['classroom'] },
        { autoStart: shouldPoll },
    );

    useEffect(() => {
        if (!shouldPoll) {
            stop();
        }
    }, [shouldPoll, stop]);

    return (
        <>
            <Head title={`${classroom.name} Teams`} />
            <div className="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 md:p-8">
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

                {flash.success && (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Complete</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
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
                                    <Button disabled={processing}>
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
                                        disabled={processing}
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
                                                        disabled={processing}
                                                    >
                                                        <RefreshCw /> Retry
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                    {group.error && (
                                        <p className="text-destructive text-sm">
                                            {group.error}
                                        </p>
                                    )}
                                    <div className="divide-y rounded-lg border">
                                        {group.students.map((student) => (
                                            <div
                                                key={student.id}
                                                className="px-4 py-3"
                                            >
                                                <p className="font-medium">
                                                    {student.name}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {student.github_login
                                                        ? `@${student.github_login}`
                                                        : 'Not joined'}
                                                </p>
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
