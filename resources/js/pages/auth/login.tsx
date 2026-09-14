import { Form, Head } from '@inertiajs/react';
import { Github, GraduationCap, UserRound } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { redirect } from '@/routes/github';
import { login as localLogin } from '@/routes/local';

export default function Login({
    localAuthEnabled,
}: {
    localAuthEnabled: boolean;
}) {
    return (
        <>
            <Head title="Log in" />

            <div className="grid gap-4">
                <a
                    href={redirect.url()}
                    className={cn(buttonVariants({ size: 'lg' }), 'w-full')}
                    data-test="github-login-button"
                >
                    <Github />
                    Continue with GitHub
                </a>

                {localAuthEnabled && (
                    <div className="grid gap-3 border-t pt-4">
                        <p className="text-muted-foreground text-center text-sm">
                            Local development bypass
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Form {...localLogin.form('teacher')}>
                                <button
                                    type="submit"
                                    className={cn(
                                        buttonVariants({
                                            variant: 'outline',
                                        }),
                                        'w-full',
                                    )}
                                >
                                    <GraduationCap /> Teacher
                                </button>
                            </Form>
                            <Form {...localLogin.form('student')}>
                                <button
                                    type="submit"
                                    className={cn(
                                        buttonVariants({
                                            variant: 'outline',
                                        }),
                                        'w-full',
                                    )}
                                >
                                    <UserRound /> Student
                                </button>
                            </Form>
                            <Form {...localLogin.form('pending-student')}>
                                <button
                                    type="submit"
                                    className={cn(
                                        buttonVariants({
                                            variant: 'outline',
                                        }),
                                        'w-full',
                                    )}
                                >
                                    <UserRound /> Unlinked
                                </button>
                            </Form>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Sign in with your GitHub account to continue',
};
