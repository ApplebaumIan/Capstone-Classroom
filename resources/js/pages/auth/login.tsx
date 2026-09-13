import { Head } from '@inertiajs/react';
import { Github } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { redirect } from '@/routes/github';

export default function Login() {
    return (
        <>
            <Head title="Log in" />

            <a
                href={redirect.url()}
                className={cn(buttonVariants({ size: 'lg' }), 'w-full')}
                data-test="github-login-button"
            >
                <Github />
                Continue with GitHub
            </a>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Sign in with your GitHub account to continue',
};
