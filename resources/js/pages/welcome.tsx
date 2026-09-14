import { Head, Link, usePage } from '@inertiajs/react';
import { Github, GraduationCap, Users } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';
import { redirect as githubLogin } from '@/routes/github';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="GitHub onboarding for capstone teams" />
            <main className="min-h-screen bg-[#f4f1e8] text-[#172019] dark:bg-[#111713] dark:text-[#edf2ed]">
                <div className="mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-8 lg:px-10">
                    <header className="flex items-center justify-between border-b border-current/15 pb-6">
                        <div className="flex items-center gap-3 font-semibold tracking-tight">
                            <span className="flex size-10 items-center justify-center rounded-full bg-[#d94b2b] text-white">
                                <GraduationCap />
                            </span>
                            Capstone Classroom
                        </div>
                        <Link
                            href={auth.user ? dashboard() : login()}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <Github />{' '}
                            {auth.user ? 'Open classroom' : 'Sign in'}
                        </Link>
                    </header>

                    <section className="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[1.3fr_0.7fr]">
                        <div className="space-y-8">
                            <p className="font-mono text-sm tracking-[0.24em] text-[#d94b2b] uppercase">
                                CIS 4398 · onboarding only
                            </p>
                            <h1 className="max-w-4xl text-5xl leading-[0.98] font-semibold tracking-[-0.045em] sm:text-7xl">
                                Move capstone teams from Canvas to GitHub.
                            </h1>
                            <p className="max-w-2xl text-lg leading-8 text-current/70">
                                Import one roster. Students claim their names.
                                Teams, repositories, access, and project sites
                                get prepared automatically.
                            </p>
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className={cn(
                                        buttonVariants({ size: 'lg' }),
                                        'bg-[#d94b2b] text-white hover:bg-[#bd3f24]',
                                    )}
                                >
                                    <Github /> Open dashboard
                                </Link>
                            ) : (
                                <a
                                    href={githubLogin.url()}
                                    className={cn(
                                        buttonVariants({ size: 'lg' }),
                                        'bg-[#d94b2b] text-white hover:bg-[#bd3f24]',
                                    )}
                                >
                                    <Github /> Continue with GitHub
                                </a>
                            )}
                        </div>

                        <div className="relative rounded-[2rem] border border-current/15 bg-[#fffdf7] p-7 shadow-[12px_12px_0_0_#172019] dark:bg-[#19221c] dark:shadow-[12px_12px_0_0_#d94b2b]">
                            <Users className="size-10 text-[#d94b2b]" />
                            <div className="mt-8 grid gap-6">
                                {[
                                    'Install GitHub App',
                                    'Upload Canvas CSV',
                                    'Share one join link',
                                ].map((step, index) => (
                                    <div
                                        key={step}
                                        className="flex items-center gap-4 border-t border-current/15 pt-4"
                                    >
                                        <span className="font-mono text-sm text-current/45">
                                            0{index + 1}
                                        </span>
                                        <span className="font-medium">
                                            {step}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
