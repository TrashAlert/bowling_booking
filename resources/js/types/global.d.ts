import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            // The business name, and the address of its logo (null when it
            // has none), as set under Settings.
            name: string;
            logoUrl: string | null;
            // How customers can reach the business; each is null until an
            // admin fills it in.
            contact: {
                phone: string | null;
                address: string | null;
                mapUrl: string | null;
            };
            version: string;
            // The symbol shown in front of amounts of money, e.g. "RM".
            currencySymbol: string;
            // Reservation requests waiting for staff; null for non-staff.
            requestsWaiting: number | null;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
