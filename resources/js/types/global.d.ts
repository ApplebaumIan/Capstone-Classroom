import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            teacherNavigation: {
                classrooms: Array<{
                    id: number;
                    name: string;
                    organization: string | null;
                }>;
                can_create_classroom: boolean;
            };
            flash: { success?: string };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
