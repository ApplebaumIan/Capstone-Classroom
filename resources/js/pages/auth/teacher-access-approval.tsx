import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type Props = {
    teacher: {
        name: string;
        email: string;
    };
    approved: boolean;
    approval_url: string;
};

export default function TeacherAccessApproval({
    teacher,
    approved,
    approval_url,
}: Props) {
    return (
        <>
            <Head title="Approve teacher access" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <div className="bg-primary/10 text-primary flex size-12 items-center justify-center rounded-full">
                        <ShieldCheck />
                    </div>
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            Approve teacher access
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Allow {teacher.name} ({teacher.email}) to create and
                            manage classrooms.
                        </p>
                    </div>
                </div>

                {approved ? (
                    <Alert>
                        <CheckCircle2 />
                        <AlertTitle>Access approved</AlertTitle>
                        <AlertDescription>
                            {teacher.name} has been emailed and can now create
                            classrooms.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Form action={approval_url} method="post">
                        {({ processing }) => (
                            <Button className="w-full" disabled={processing}>
                                {processing
                                    ? 'Approving...'
                                    : 'Approve teacher access'}
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
