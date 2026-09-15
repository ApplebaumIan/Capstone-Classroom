import { Form, Head, Link } from '@inertiajs/react';
import { Github, School } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import {
    store as storeClassroom,
    update as updateClassroom,
} from '@/routes/classrooms';
import {
    create as connectGitHub,
    edit as installGitHub,
    store as refreshGitHub,
} from '@/routes/github/installations';

type Props = {
    classroom: { id: number; name: string } | null;
    available_installations: Array<{
        id: string;
        account_id: string;
        login: string;
        avatar_url: string | null;
    }>;
    github_connected: boolean;
};

export default function CreateClassroom({
    classroom,
    available_installations,
    github_connected,
}: Props) {
    const form = classroom
        ? updateClassroom.form(classroom.id)
        : storeClassroom.form();
    const connectUrl = connectGitHub({
        query: classroom ? { classroom: classroom.id } : {},
    });

    return (
        <>
            <Head
                title={classroom ? 'Finish classroom setup' : 'Create Class'}
            />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-8">
                <div className="space-y-2">
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {classroom ? 'Finish classroom setup' : 'Create Class'}
                    </h1>
                    <p className="text-muted-foreground">
                        Each classroom uses one GitHub organization for teams
                        and repositories.
                    </p>
                </div>

                {available_installations.length === 0 ? (
                    <Card className="border-primary/30 bg-primary/5">
                        <CardHeader>
                            <CardTitle>Connect GitHub organization</CardTitle>
                            <CardDescription>
                                Install or configure Capstone Classroom for the
                                organization used by this class.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-3">
                            {github_connected ? (
                                <GitHubInstallationControls />
                            ) : (
                                <Button asChild size="lg">
                                    <a href={connectUrl.url}>
                                        <Github /> Connect GitHub
                                    </a>
                                </Button>
                            )}
                            <Button asChild variant="outline" size="lg">
                                <Link href={dashboard()}>Cancel</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Class details</CardTitle>
                            <CardDescription>
                                Name classroom, then select its GitHub
                                organization.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form {...form} className="grid gap-6">
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Classroom name
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={
                                                    classroom?.name ?? ''
                                                }
                                                placeholder="Classroom A"
                                                required
                                                autoFocus
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <fieldset className="grid gap-3">
                                            <legend className="text-sm font-medium">
                                                GitHub organization
                                            </legend>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {available_installations.map(
                                                    (installation, index) => (
                                                        <label
                                                            key={
                                                                installation.id
                                                            }
                                                            className="has-checked:border-primary has-checked:bg-primary/5 flex cursor-pointer items-center gap-3 rounded-lg border p-4"
                                                        >
                                                            <input
                                                                type="radio"
                                                                name="installation_id"
                                                                value={
                                                                    installation.id
                                                                }
                                                                defaultChecked={
                                                                    index === 0
                                                                }
                                                                required
                                                            />
                                                            {installation.avatar_url ? (
                                                                <img
                                                                    src={
                                                                        installation.avatar_url
                                                                    }
                                                                    alt=""
                                                                    className="size-9 rounded-md"
                                                                />
                                                            ) : (
                                                                <School className="text-muted-foreground size-9" />
                                                            )}
                                                            <span className="font-medium">
                                                                {
                                                                    installation.login
                                                                }
                                                            </span>
                                                        </label>
                                                    ),
                                                )}
                                            </div>
                                            <InputError
                                                message={errors.installation_id}
                                            />
                                        </fieldset>
                                        <div className="flex flex-col gap-3 sm:flex-row">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing
                                                    ? 'Saving…'
                                                    : classroom
                                                      ? 'Finish setup'
                                                      : 'Create Class'}
                                            </Button>
                                            <Button
                                                asChild
                                                type="button"
                                                variant="outline"
                                            >
                                                <Link href={dashboard()}>
                                                    Cancel
                                                </Link>
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                            <div className="mt-6 border-t pt-6">
                                <GitHubInstallationControls />
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function GitHubInstallationControls() {
    return (
        <div className="flex flex-wrap gap-3">
            <Button asChild size="lg">
                <a href={installGitHub.url()} target="_blank" rel="noreferrer">
                    <Github /> Install or configure app
                </a>
            </Button>
            <Form {...refreshGitHub.form()}>
                {({ errors, processing }) => (
                    <div className="grid gap-2">
                        <Button
                            type="submit"
                            variant="outline"
                            size="lg"
                            disabled={processing}
                        >
                            {processing
                                ? 'Refreshing…'
                                : 'Refresh organizations'}
                        </Button>
                        <InputError message={errors.github} />
                    </div>
                )}
            </Form>
        </div>
    );
}

CreateClassroom.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Create Class', href: '#' },
    ],
};
