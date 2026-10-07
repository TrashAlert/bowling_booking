import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The logo an admin uploaded under Settings, or the fallback while the
 * business has none. The business name is always written next to it, so the
 * picture itself is left unannounced to screen readers.
 */
export default function BusinessLogo({
    className,
    fallback = null,
}: {
    className?: string;
    fallback?: ReactNode;
}) {
    const { logoUrl } = usePage().props;

    return logoUrl ? (
        <img src={logoUrl} alt="" className={cn('object-contain', className)} />
    ) : (
        fallback
    );
}
