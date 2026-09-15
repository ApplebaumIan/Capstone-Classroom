import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    FolderKanban,
    Github,
    Plus,
    RefreshCw,
    School,
    Trash2,
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
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    create as createClassroom,
    destroy as destroyClassroom,
    edit as editClassroom,
    students,
    teams,
} from '@/routes/classrooms';

type Group = {
    id: number;
    name: string;
    status: 'waiting' | 'provisioning' | 'ready' | 'failed';
    error: string | null;
    team_url: string | null;
    repository_url: string | null;
    pages_url: string | null;
};

type Props = {
    mode: 'teacher' | 'student';
    classrooms?: TeacherClassroom[];
    claim?: {
        name: string;
        sections: string;
        classroom: string;
        join_url: string;
        group: Group;
    };
};

type TeacherClassroom = {
    id: number;
    name: string;
    organization: string | null;
    installed: boolean;
    student_count: number;
    claimed_count: number;
    team_count: number;
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

function DeleteClassroomDialog({ classroom }: { classroom: TeacherClassroom }) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <Dialog
            onOpenChange={(open) => {
                if (!open) {
                    setConfirmation('');
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="destructive">
                    <Trash2 /> Delete
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {classroom.name}?</DialogTitle>
                    <DialogDescription>
                        This permanently deletes the classroom, roster, teams,
                        and sync history from Capstone Classroom. GitHub
                        repositories and teams will remain unchanged.
                    </DialogDescription>
                </DialogHeader>
                <Form {...destroyClassroom.form(classroom.id)}>
                    {({ errors, processing }) => (
                        <div className="grid gap-4">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`delete-classroom-${classroom.id}`}
                                >
                                    Type <strong>{classroom.name}</strong> to
                                    confirm
                                </Label>
                                <Input
                                    id={`delete-classroom-${classroom.id}`}
                                    name="confirmation"
                                    value={confirmation}
                                    onChange={(event) =>
                                        setConfirmation(event.target.value)
                                    }
                                    autoComplete="off"
                                />
                                <InputError message={errors.confirmation} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={
                                        processing ||
                                        confirmation !== classroom.name
                                    }
                                >
                                    <Trash2 /> Delete classroom
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Dashboard({ mode, classrooms = [], claim }: Props) {
    const { flash } = usePage().props;

    if (mode === 'student' && claim) {
        return <StudentDashboard claim={claim} />;
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                    <div className="space-y-2">
                        <Badge variant="outline">Teacher workspace</Badge>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            Classrooms
                        </h1>
                        <p className="text-muted-foreground">
                            Manage rosters and GitHub project teams by
                            organization.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={createClassroom()}>
                            <Plus /> Create Class
                        </Link>
                    </Button>
                </div>

                {flash.success && (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Complete</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {classrooms.length === 0 ? (
                    <Card className="border-dashed">
                        <CardHeader>
                            <CardTitle>Create your first classroom</CardTitle>
                            <CardDescription>
                                Connect one GitHub organization, then import
                                your Canvas roster.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild>
                                <Link href={createClassroom()}>
                                    <Plus /> Create Class
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {classrooms.map((classroom) => (
                            <Card key={classroom.id} className="h-full">
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="space-y-1">
                                            <CardTitle>
                                                {classroom.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {classroom.organization
                                                    ? `@${classroom.organization}`
                                                    : 'Setup required'}
                                            </CardDescription>
                                        </div>
                                        <School className="text-muted-foreground size-5" />
                                    </div>
                                </CardHeader>
                                <CardContent className="grid grid-cols-2 gap-3">
                                    <div className="rounded-lg border p-3">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Users className="size-4" />{' '}
                                            Students
                                        </div>
                                        <p className="mt-2 text-2xl font-semibold">
                                            {classroom.claimed_count}
                                            <span className="text-muted-foreground text-sm font-normal">
                                                {' '}
                                                / {classroom.student_count}
                                            </span>
                                        </p>
                                    </div>
                                    <div className="rounded-lg border p-3">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <FolderKanban className="size-4" />
                                            Teams
                                        </div>
                                        <p className="mt-2 text-2xl font-semibold">
                                            {classroom.team_count}
                                        </p>
                                    </div>
                                </CardContent>
                                <CardFooter className="flex flex-wrap gap-2 border-t pt-6">
                                    {classroom.installed ? (
                                        <>
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={students(
                                                        classroom.id,
                                                    )}
                                                >
                                                    Student Roster
                                                </Link>
                                            </Button>
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={teams(classroom.id)}
                                                >
                                                    Teams
                                                </Link>
                                            </Button>
                                        </>
                                    ) : (
                                        <Button asChild>
                                            <Link
                                                href={editClassroom(
                                                    classroom.id,
                                                )}
                                            >
                                                Continue setup
                                            </Link>
                                        </Button>
                                    )}
                                    <DeleteClassroomDialog
                                        classroom={classroom}
                                    />
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
