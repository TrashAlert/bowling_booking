import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editBranding } from '@/routes/branding';
import { edit as editDashboardParts } from '@/routes/dashboard-parts';
import { edit as editDeposit } from '@/routes/deposit';
import { edit as editLanes } from '@/routes/lanes';
import { edit as editOnlineWaitlist } from '@/routes/online-waitlist';
import { edit as editOpeningHours } from '@/routes/opening-hours';
import { edit } from '@/routes/profile';
import { edit as editRules } from '@/routes/rules';
import { edit as editSecurity } from '@/routes/security';
import { index as users } from '@/routes/users';
import type { NavItem } from '@/types';

// The settings pages, grouped under a heading each. Everyone has the first
// group; the rest are for admins only.
type NavGroup = { title: string; adminOnly: boolean; items: NavItem[] };

const navGroups: NavGroup[] = [
    {
        title: 'My account',
        adminOnly: false,
        items: [
            { title: 'Profile', href: edit(), icon: null },
            { title: 'Security', href: editSecurity(), icon: null },
            { title: 'Appearance', href: editAppearance(), icon: null },
        ],
    },
    {
        // What the place is: its name and logo, its lanes, when it is open.
        title: 'Venue',
        adminOnly: true,
        items: [
            { title: 'Business', href: editBranding(), icon: null },
            { title: 'Lanes', href: editLanes(), icon: null },
            { title: 'Opening hours', href: editOpeningHours(), icon: null },
        ],
    },
    {
        // How the waitlist and reservations behave.
        title: 'Bookings',
        adminOnly: true,
        items: [
            { title: 'Booking rules', href: editRules(), icon: null },
            {
                title: 'Online waitlist',
                href: editOnlineWaitlist(),
                icon: null,
            },
            { title: 'Deposit', href: editDeposit(), icon: null },
        ],
    },
    {
        title: 'Staff',
        adminOnly: true,
        items: [
            { title: 'Users', href: users(), icon: null },
            { title: 'Dashboard', href: editDashboardParts(), icon: null },
        ],
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage().props;
    const isAdmin = auth.user.role === 'admin';
    const groups = navGroups.filter((group) => isAdmin || !group.adminOnly);

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description={
                    isAdmin
                        ? 'Manage your account and how the venue runs'
                        : 'Manage your profile and account settings'
                }
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col gap-3" aria-label="Settings">
                        {groups.map((group) => (
                            // Each group sits in its own box under a shaded
                            // title bar, so the titles can't be taken for
                            // links. Staff have one group, which needs neither.
                            <div
                                key={group.title}
                                className={cn(
                                    groups.length > 1 &&
                                        'overflow-hidden rounded-lg border',
                                )}
                            >
                                {groups.length > 1 && (
                                    <p className="border-b bg-muted px-3 py-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {group.title}
                                    </p>
                                )}
                                <div
                                    className={cn(
                                        'space-y-1',
                                        groups.length > 1 && 'p-1',
                                    )}
                                >
                                    {group.items.map((item) => (
                                        <Button
                                            key={toUrl(item.href)}
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                            className={cn(
                                                'w-full justify-start',
                                                {
                                                    'bg-muted font-semibold':
                                                        isCurrentOrParentUrl(
                                                            item.href,
                                                        ),
                                                },
                                            )}
                                        >
                                            <Link href={item.href}>
                                                {item.icon && (
                                                    <item.icon className="h-4 w-4" />
                                                )}
                                                {item.title}
                                            </Link>
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
