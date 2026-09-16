import { Form, Head, usePage } from '@inertiajs/react';
import { CheckCircle2, Clipboard, Upload, UserRoundX } from 'lucide-react';
import { useState } from 'react';
import DeleteClassroomDialog from '@/components/delete-classroom-dialog';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { dashboard } from '@/routes';
import { store as assignRosterClaim } from '@/routes/pending-roster-claims';
import { destroy as resetClaim } from '@/routes/roster-claims';
import { store as storeRoster } from '@/routes/roster';
import { store as skipRosterImport } from '@/routes/roster-import-skips';

type Props = {
    classroom: {
        id: number;
        name: string;
        organization: string | null;
        join_url: string;
        installed: boolean;
        roster_imported: boolean;
        roster_skipped: boolean;
        repository_visibility: 'public' | 'private';
        students: Array<{
            id: number;
            name: string;
            sections: string;
            group: string;
            github_login: string | null;
            claimed: boolean;
        }>;
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
};

export default function Students({ classroom }: Props) {
    const { flash } = usePage().props;
    const [copied, setCopied] = useState(false);

    const copyJoinLink = async () => {
        await navigator.clipboard.writeText(classroom.join_url);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    return (
        <>
            <Head title={`${classroom.name} Student Roster`} />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="space-y-2">
                        <Badge variant="outline">Student Roster</Badge>
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

                <Card>
                    <CardHeader>
                        <CardTitle>Classroom join link</CardTitle>
                        <CardDescription>
                            Share this permanent link with students. Teachers
                            can also open it to test team creation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 sm:flex-row">
                        <Input
                            readOnly
                            value={classroom.join_url}
                            className="font-mono text-xs"
                        />
                        <Button variant="outline" onClick={copyJoinLink}>
                            <Clipboard /> {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </CardContent>
                </Card>

                {classroom.pending_students.length > 0 && (
                    <Card className="border-amber-500/40 bg-amber-500/5">
                        <CardHeader>
                            <div className="flex items-start gap-3">
                                <UserRoundX className="mt-0.5 size-5 text-amber-700 dark:text-amber-400" />
                                <div className="space-y-1">
                                    <CardTitle>Unlinked students</CardTitle>
                                    <CardDescription>
                                        Match GitHub accounts to Canvas roster
                                        names.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                            {classroom.pending_students.map((student) => (
                                <Form
                                    key={student.id}
                                    {...assignRosterClaim.form([
                                        classroom.id,
                                        student.id,
                                    ])}
                                    className="bg-background grid gap-3 rounded-lg border p-4"
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
                                                <option value="" disabled>
                                                    Select Canvas name
                                                </option>
                                                {classroom.unclaimed_entries.map(
                                                    (entry) => (
                                                        <option
                                                            key={entry.id}
                                                            value={entry.id}
                                                        >
                                                            {entry.name} -{' '}
                                                            {entry.group}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <Button
                                                size="sm"
                                                disabled={
                                                    processing ||
                                                    classroom.unclaimed_entries
                                                        .length === 0
                                                }
                                            >
                                                Link student
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {!classroom.roster_imported && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Import Canvas roster</CardTitle>
                            <CardDescription>
                                Upload group export. Choose visibility for all
                                team repositories.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...storeRoster.form(classroom.id)}
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
                                                                classroom.repository_visibility
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
                                            <Upload />
                                            {processing
                                                ? 'Importing…'
                                                : 'Import roster'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                        {!classroom.roster_skipped && (
                            <CardFooter className="flex-col items-stretch justify-between gap-4 border-t pt-6 sm:flex-row sm:items-center">
                                <p className="text-muted-foreground text-sm">
                                    Skip import to create teams manually.
                                </p>
                                <Form {...skipRosterImport.form(classroom.id)}>
                                    {({ processing }) => (
                                        <Button
                                            variant="ghost"
                                            disabled={processing}
                                        >
                                            Skip for now
                                        </Button>
                                    )}
                                </Form>
                            </CardFooter>
                        )}
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Students</CardTitle>
                        <CardDescription>
                            {classroom.students.length} Canvas roster entries
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {classroom.students.length === 0 ? (
                            <div className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                                No Canvas students imported.
                            </div>
                        ) : (
                            <div className="divide-y rounded-lg border">
                                {classroom.students.map((student) => (
                                    <div
                                        key={student.id}
                                        className="grid gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] sm:items-center"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {student.name}
                                            </p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {student.sections}
                                            </p>
                                        </div>
                                        <div className="min-w-0 text-sm">
                                            <p className="truncate">
                                                {student.group}
                                            </p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {student.github_login
                                                    ? `@${student.github_login}`
                                                    : 'GitHub not linked'}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    student.claimed
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {student.claimed
                                                    ? 'Linked'
                                                    : 'Unlinked'}
                                            </Badge>
                                            {student.claimed && (
                                                <Form
                                                    {...resetClaim.form([
                                                        classroom.id,
                                                        student.id,
                                                    ])}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Reset
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Students.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Student Roster', href: '#' },
    ],
};
