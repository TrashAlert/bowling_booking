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
            // The business's social media accounts, as the addresses to open
            // them at; each is null until an admin fills it in.
            social: {
                whatsapp: string | null;
                instagram: string | null;
                facebook: string | null;
                tiktok: string | null;
            };
            // The banner across the top of the customer pages; null when
            // there is none or it has ended.
            announcement: string | null;
            // The colour customer pages use for buttons, with the text
            // colour that reads on it; null for plain black and white.
            brand: { color: string; textColor: string } | null;
            version: string;
            // The symbol shown in front of amounts of money, e.g. "RM".
            currencySymbol: string;
            // Reservation requests waiting for staff; null for non-staff.
            requestsWaiting: number | null;
            // On the public demo only: the logins to try, and when it starts
            // afresh each day ("09:00", venue time). Null everywhere else.
            demo: {
                logins: { role: string; email: string; password: string }[];
                resetsAt: string;
            } | null;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
