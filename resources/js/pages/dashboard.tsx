import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    Clipboard,
    ExternalLink,
    Github,
    RefreshCw,
    Upload,
    Users,
} from 'lucide-react';
import { useState } from 'react';
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
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as installGitHub,
    store as storeInstallation,
} from '@/routes/github/installations';
import { store as retryProvisioning } from '@/routes/group-provisioning';
import { store as assignRosterClaim } from '@/routes/pending-roster-claims';
import { destroy as resetClaim } from '@/routes/roster-claims';
import { store as storeRoster } from '@/routes/roster';

type Group = {
    id: number;
    name: string;
    status: 'waiting' | 'provisioning' | 'ready' | 'failed';
    error: string | null;
    team_url: string | null;
    repository_url: string | null;
    pages_url: string | null;
    students?: Array<{
        id: number;
        name: string;
        sections: string;
        github_login: string | null;
        claimed: boolean;
    }>;
};

type Props = {
    mode: 'teacher' | 'student';
    classroom?: {
        name: string;
        join_url: string;
        organization: string | null;
        installed: boolean;
        roster_imported: boolean;
        repository_visibility: 'public' | 'private';
        student_count: number;
        claimed_count: number;
        groups: Group[];
        pending_students: Array<{
            id: number;
            name: string;
            github_login: string | null;
        }>;
        unclaimed_entries: Array<{
            id: number;
            name: string;
            group: string;
        }>;
    };
    claim?: {
        name: string;
        sections: string;
        classroom: string;
        join_url: string;
        group: Group;
    };
    available_installations: Array<{
        id: string;
        login: string;
        avatar_url: string | null;
    }>;
};

function StatusBadge({ status }: { status: Group['status'] }) {
    const labels = {
        waiting: 'Waiting for first student',
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

function StudentDashboard({ claim }: { claim: NonNullable<Props['claim']> }) {
    usePoll(10_000, { only: ['claim'] });

    return (
        <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-8">
            <div className="space-y-2">
                <Badge variant="outline">Student onboarding</Badge>
                <h1 className="text-3xl font-semibold tracking-tight">
                    Welcome, {claim.name}
                </h1>
                <p className="text-muted-foreground">
                    {claim.classroom} · {claim.sections}
                </p>
            </div>
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="space-y-1">
                            <CardTitle>{claim.group.name}</CardTitle>
                            <CardDescription>
                                Your GitHub team workspace
                            </CardDescription>
                        </div>
                        <StatusBadge status={claim.group.status} />
                    </div>
                </CardHeader>
                <CardContent className="flex flex-wrap gap-3">
                    {claim.group.repository_url && (
                        <a
                            className={buttonVariants()}
                            href={claim.group.repository_url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Github /> Repository <ExternalLink />
                        </a>
                    )}
                    {claim.group.pages_url && (
                        <a
                            className={buttonVariants({ variant: 'outline' })}
                            href={claim.group.pages_url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Project site <ExternalLink />
                        </a>
                    )}
                    {claim.group.status === 'provisioning' && (
                        <p className="text-muted-foreground text-sm">
                            Keep this page open. Status refreshes automatically.
                        </p>
                    )}
                    {claim.group.error && (
                        <p className="text-destructive text-sm">
                            {claim.group.error}
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

export default function Dashboard({
    mode,
    classroom,
    claim,
    available_installations,
}: Props) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(false);
    const shouldPoll =
        classroom?.groups.some((group) => group.status === 'provisioning') ??
        false;

    usePoll(10_000, { only: ['classroom'] }, { autoStart: shouldPoll });

    if (mode === 'student' && claim) {
        return <StudentDashboard claim={claim} />;
    }

    if (!classroom) {
        return null;
    }

    const copyJoinLink = async () => {
        await navigator.clipboard.writeText(classroom.join_url);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    return (
        <>
            <Head title="Classroom" />
            <div className="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                    <div className="space-y-2">
                        <Badge variant="outline">Teacher workspace</Badge>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {classroom.name}
                        </h1>
                        <p className="text-muted-foreground">
                            {classroom.organization
                                ? `Connected to ${classroom.organization}`
                                : 'Connect one GitHub organization to begin.'}
                        </p>
                    </div>
                    {classroom.installed && classroom.roster_imported && (
                        <div className="flex items-center gap-3 text-sm">
                            <Users className="size-4" />
                            <span>
                                {classroom.claimed_count} of{' '}
                                {classroom.student_count} joined
                            </span>
                        </div>
                    )}
                </div>

                {flash.success && (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Complete</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {!classroom.installed &&
                    available_installations.length === 0 && (
                        <Card className="border-primary/30 bg-primary/5">
                            <CardHeader>
                                <CardTitle>
                                    1. Install Capstone Classroom
                                </CardTitle>
                                <CardDescription>
                                    Grant organization access for team and
                                    repository provisioning.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <a
                                    href={installGitHub.url()}
                                    className={cn(
                                        buttonVariants({ size: 'lg' }),
                                        'w-full sm:w-auto',
                                    )}
                                >
                                    <Github /> Install GitHub App
                                </a>
                            </CardContent>
                        </Card>
                    )}

                {!classroom.installed && available_installations.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Select organization</CardTitle>
                            <CardDescription>
                                Choose installation used for this classroom.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2">
                            {available_installations.map((installation) => (
                                <Form
                                    key={installation.id}
                                    {...storeInstallation.form()}
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="installation_id"
                                                value={installation.id}
                                            />
                                            <Button
                                                variant="outline"
                                                className="h-auto w-full justify-start p-4"
                                                disabled={processing}
                                            >
                                                {installation.avatar_url && (
                                                    <img
                                                        src={
                                                            installation.avatar_url
                                                        }
                                                        alt=""
                                                        className="size-8 rounded-md"
                                                    />
                                                )}
                                                <span className="font-medium">
                                                    {installation.login}
                                                </span>
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {classroom.installed && !classroom.roster_imported && (
                    <Card>
                        <CardHeader>
                            <CardTitle>2. Import Canvas roster</CardTitle>
                            <CardDescription>
                                Upload group export. Choose one visibility for
                                all team repositories.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...storeRoster.form()}
                                className="grid gap-5"
                            >
                                {({ errors, processing, progress }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="roster">
                                                Roster CSV
                                            </Label>
                                            <Input
                                                id="roster"
                                                name="roster"
                                                type="file"
                                                accept=".csv,text/csv"
                                                required
                                            />
                                            <InputError
                                                message={errors.roster}
                                            />
                                        </div>
                                        <fieldset className="grid gap-2">
                                            <legend className="text-sm font-medium">
                                                Repository visibility
                                            </legend>
                                            <div className="flex flex-wrap gap-4">
                                                {(
                                                    [
                                                        'private',
                                                        'public',
                                                    ] as const
                                                ).map((visibility) => (
                                                    <label
                                                        key={visibility}
                                                        className="flex items-center gap-2 rounded-lg border px-4 py-3 text-sm capitalize"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="repository_visibility"
                                                            value={visibility}
                                                            defaultChecked={
                                                                visibility ===
                                                                'private'
                                                            }
                                                        />
                                                        {visibility}
                                                    </label>
                                                ))}
                                            </div>
                                            <InputError
                                                message={
                                                    errors.repository_visibility
                                                }
                                            />
                                        </fieldset>
                                        {progress && (
                                            <progress
                                                className="w-full"
                                                value={progress.percentage}
                                                max="100"
                                            />
                                        )}
                                        <Button
                                            size="lg"
                                            disabled={processing}
                                            className="w-full sm:w-fit"
                                        >
                                            <Upload />{' '}
                                            {processing
                                                ? 'Importing…'
                                                : 'Import roster'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                )}

                {classroom.installed && classroom.roster_imported && (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>Classroom join link</CardTitle>
                                <CardDescription>
                                    Share this permanent link with students.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 sm:flex-row">
                                <Input
                                    readOnly
                                    value={classroom.join_url}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    variant="outline"
                                    onClick={copyJoinLink}
                                >
                                    <Clipboard /> {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </CardContent>
                        </Card>

                        {classroom.pending_students.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Unlinked GitHub accounts
                                    </CardTitle>
                                    <CardDescription>
                                        Match students who skipped roster
                                        selection to their Canvas name.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                                    {classroom.pending_students.map(
                                        (student) => (
                                            <Form
                                                key={student.id}
                                                {...assignRosterClaim.form(
                                                    student.id,
                                                )}
                                                className="grid gap-3 rounded-lg border p-4"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <div>
                                                            <p className="font-medium">
                                                                {student.name}
                                                            </p>
                                                            <p className="text-muted-foreground text-xs">
                                                                {student.github_login
                                                                    ? `@${student.github_login}`
                                                                    : 'GitHub username unavailable'}
                                                            </p>
                                                        </div>
                                                        <select
                                                            name="roster_entry_id"
                                                            required
                                                            defaultValue=""
                                                            className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Select Canvas
                                                                name
                                                            </option>
                                                            {classroom.unclaimed_entries.map(
                                                                (entry) => (
                                                                    <option
                                                                        key={
                                                                            entry.id
                                                                        }
                                                                        value={
                                                                            entry.id
                                                                        }
                                                                    >
                                                                        {
                                                                            entry.name
                                                                        }{' '}
                                                                        -{' '}
                                                                        {
                                                                            entry.group
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing ||
                                                                classroom
                                                                    .unclaimed_entries
                                                                    .length ===
                                                                    0
                                                            }
                                                        >
                                                            Link student
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ),
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {classroom.groups.map((group) => (
                                <Card key={group.id} className="h-full">
                                    <CardHeader>
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="space-y-1">
                                                <CardTitle>
                                                    {group.name}
                                                </CardTitle>
                                                <CardDescription>
                                                    {group.students?.filter(
                                                        (student) =>
                                                            student.claimed,
                                                    ).length ?? 0}{' '}
                                                    of{' '}
                                                    {group.students?.length ??
                                                        0}{' '}
                                                    students joined
                                                </CardDescription>
                                            </div>
                                            <StatusBadge
                                                status={group.status}
                                            />
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
                                                    {...retryProvisioning.form(
                                                        group.id,
                                                    )}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
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
                                            {group.students?.map((student) => (
                                                <div
                                                    key={student.id}
                                                    className="flex flex-col justify-between gap-2 px-4 py-3 sm:flex-row sm:items-center"
                                                >
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
                                                    {student.claimed && (
                                                        <Form
                                                            {...resetClaim.form(
                                                                student.id,
                                                            )}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Reset claim
                                                                </Button>
                                                            )}
                                                        </Form>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Classroom', href: dashboard() }],
};
