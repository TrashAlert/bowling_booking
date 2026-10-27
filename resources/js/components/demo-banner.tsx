import { Link, usePage } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { formatTimeOfDay } from '@/lib/format';
import { home } from '@/routes';
import { board } from '@/routes/staff';

/**
 * On the public demo only, a strip across the top of every page saying the
 * venue is made up, shared and reset daily, with links between the
 * customers' side and the staff side. Nothing anywhere else.
 */
export default function DemoBanner() {
    const { demo, auth } = usePage().props;

    if (demo === null) {
        return null;
    }

    return (
        <div
            role="note"
            aria-label="Demo"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-100 px-4 py-2 text-center text-sm text-amber-950 dark:bg-amber-500/20 dark:text-amber-100"
        >
            <p className="flex items-center gap-2">
                <FlaskConical aria-hidden className="size-4 shrink-0" />
                <span>
                    <span className="font-semibold">Demo.</span> A made-up venue
                    that every visitor shares. It starts afresh every day at{' '}
                    {formatTimeOfDay(demo.resetsAt)}.
                </span>
            </p>
            <span className="flex gap-3 font-medium">
                <Link href={home()} className="underline underline-offset-4">
                    Customer side
                </Link>
                <Link href={board()} className="underline underline-offset-4">
                    {auth.user ? 'Staff side' : 'Staff side (log in)'}
                </Link>
            </span>
        </div>
    );
}
