import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    Github,
    RefreshCw,
    SkipForward,
    UserRoundCheck,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { join } from '@/routes/classrooms';
import { store as skipRosterClaim } from '@/routes/pending-classroom-students';
import { store as claimEntry } from '@/routes/roster-claims';
import { store as createStudentGroup } from '@/routes/student-classroom-groups';
import { store as joinStudentGroup } from '@/routes/student-classroom-group-memberships';

type Props = {
    classroom: { name: string; join_code: string };
    student_team_creation_enabled: boolean;
    claim: null | {
        name: string;
        sections: string;
        group: {
            name: string;
            status: 'waiting' | 'provisioning' | 'ready' | 'failed';
            error: string | null;
            repository_url: string | null;
            pages_url: string | null;
        };
    };
    entries: Array<{ id: number; name: string; sections: string }>;
    available_groups: Array<{
        id: number;
        name: string;
        status: 'waiting' | 'provisioning' | 'ready' | 'failed';
        student_count: number;
    }>;
};

export default function JoinClassroom({
    classroom,
    claim,
    entries,
    available_groups,
    student_team_creation_enabled,
}: Props) {
    const { flash } = usePage().props;
    usePoll(
        10_000,
        { only: ['claim'] },
        { autoStart: claim?.group.status === 'provisioning' },
    );

    return (
        <>
            <Head title={`Join ${classroom.name}`} />
            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <Badge variant="outline">Student onboarding</Badge>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {classroom.name}
                    </h1>
                    <p className="text-muted-foreground">
                        Select your Canvas roster entry once. Your GitHub
                        account becomes linked to that team.
                    </p>
                </div>

                {flash.success && (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Claim saved</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {claim ? (
                    <Card>
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <CardTitle>{claim.group.name}</CardTitle>
                                    <CardDescription>
                                        {claim.name} · {claim.sections}
                                    </CardDescription>
                                </div>
                                <Badge
                                    variant={
                                        claim.group.status === 'failed'
                                            ? 'destructive'
                                            : claim.group.status === 'ready'
                                              ? 'default'
                                              : 'secondary'
                                    }
                                >
                                    {claim.group.status === 'provisioning' && (
                                        <RefreshCw className="animate-spin" />
                                    )}
                                    {claim.group.status}
                                </Badge>
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
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                    href={claim.group.pages_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Project site <ExternalLink />
                                </a>
                            )}
                            {claim.group.status === 'provisioning' && (
                                <p className="text-muted-foreground w-full text-sm">
                                    GitHub may send an organization invitation.
                                    Accept it to activate team access.
                                </p>
                            )}
                            {claim.group.error && (
                                <p className="text-destructive w-full text-sm">
                                    {claim.group.error}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>Find your name</CardTitle>
                                <CardDescription>
                                    Claim cannot be changed without teacher
                                    reset.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <div className="col-span-full">
                                    <InputError
                                        message={
                                            usePage().props.errors.roster_entry
                                        }
                                    />
                                </div>
                                {entries.map((entry) => (
                                    <Form
                                        key={entry.id}
                                        {...claimEntry.form({
                                            classroom: classroom.join_code,
                                            rosterEntry: entry.id,
                                        })}
                                        className="h-full"
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="outline"
                                                className="h-full min-h-20 w-full justify-between p-4 text-left"
                                                disabled={processing}
                                            >
                                                <span>
                                                    <span className="block font-medium">
                                                        {entry.name}
                                                    </span>
                                                    <span className="text-muted-foreground block text-xs">
                                                        {entry.sections}
                                                    </span>
                                                </span>
                                                <UserRoundCheck />
                                            </Button>
                                        )}
                                    </Form>
                                ))}
                                {entries.length === 0 && (
                                    <p className="text-muted-foreground col-span-full py-8 text-center text-sm">
                                        No unclaimed roster entries remain.
                                    </p>
                                )}
                            </CardContent>
                            <CardFooter className="flex-col items-stretch justify-between gap-4 border-t pt-6 sm:flex-row sm:items-center">
                                <p className="text-muted-foreground text-sm">
                                    Can't find your name? Let your teacher link
                                    your GitHub account.
                                </p>
                                <Form
                                    {...skipRosterClaim.form(
                                        classroom.join_code,
                                    )}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            disabled={processing}
                                        >
                                            Skip for now <SkipForward />
                                        </Button>
                                    )}
                                </Form>
                            </CardFooter>
                        </Card>
                        {available_groups.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Join an existing team</CardTitle>
                                    <CardDescription>
                                        Choose a team that was created outside
                                        the Canvas roster.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    <div className="col-span-full">
                                        <InputError
                                            message={
                                                usePage().props.errors.team
                                            }
                                        />
                                    </div>
                                    {available_groups.map((group) => (
                                        <Form
                                            key={group.id}
                                            {...joinStudentGroup.form({
                                                classroom: classroom.join_code,
                                                classroomGroup: group.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    className="h-auto w-full justify-between p-4 text-left"
                                                    disabled={processing}
                                                >
                                                    <span>
                                                        <span className="block font-medium">
                                                            {group.name}
                                                        </span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {
                                                                group.student_count
                                                            }{' '}
                                                            {group.student_count ===
                                                            1
                                                                ? 'student'
                                                                : 'students'}
                                                        </span>
                                                    </span>
                                                    <UserRoundCheck />
                                                </Button>
                                            )}
                                        </Form>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                        {student_team_creation_enabled && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Create a new team</CardTitle>
                                    <CardDescription>
                                        Start a project team instead of
                                        selecting an existing Canvas roster
                                        entry.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Form
                                        {...createStudentGroup.form(
                                            classroom.join_code,
                                        )}
                                        className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="student-team-name">
                                                        Team name
                                                    </Label>
                                                    <Input
                                                        id="student-team-name"
                                                        name="name"
                                                        placeholder="Project Atlas"
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <Button disabled={processing}>
                                                    Create team
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

JoinClassroom.layout = {
    breadcrumbs: [{ title: 'Join classroom', href: '#' }],
};
