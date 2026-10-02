import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { home } from '@/routes';

/**
 * The frame around a customer's page: the business name, which leads back to
 * the front page, above one narrow column.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="mx-auto flex w-full max-w-md items-center p-4 sm:p-6">
                <Link
                    href={home()}
                    className="flex items-center gap-2 truncate font-semibold tracking-tight"
                >
                    <ArrowLeft aria-hidden className="size-4 shrink-0" />
                    {name}
                </Link>
            </header>

            <main className="mx-auto flex w-full max-w-md flex-1 flex-col gap-4 p-4 sm:p-6">
                {children}
            </main>
        </div>
    );
}
