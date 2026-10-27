import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import AnnouncementBanner from '@/components/announcement-banner';
import BrandColors from '@/components/brand-colors';
import BusinessLogo from '@/components/business-logo';
import ContactDetails from '@/components/contact-details';
import DemoBanner from '@/components/demo-banner';
import ThemeToggle from '@/components/theme-toggle';
import { home } from '@/routes';

/**
 * The frame around a customer's page: the business name, which leads back to
 * the front page, and a button to switch between light and dark, above one
 * narrow column.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <BrandColors />
            <DemoBanner />
            <AnnouncementBanner />
            <header className="mx-auto flex w-full max-w-md items-center justify-between gap-4 p-4 sm:p-6">
                <Link
                    href={home()}
                    className="flex items-center gap-2 truncate font-semibold tracking-tight"
                >
                    <ArrowLeft aria-hidden className="size-4 shrink-0" />
                    <BusinessLogo className="size-6 shrink-0 rounded" />
                    {name}
                </Link>
                <ThemeToggle />
            </header>

            <main className="mx-auto flex w-full max-w-md flex-1 flex-col gap-4 p-4 sm:p-6">
                {children}
            </main>

            <footer>
                <ContactDetails className="mx-auto w-full max-w-md p-4 sm:p-6" />
            </footer>
        </div>
    );
}
