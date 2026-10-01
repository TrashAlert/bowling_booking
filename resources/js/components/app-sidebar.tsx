import { Link, usePage } from '@inertiajs/react';
import { CalendarClock, Columns3, LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { board } from '@/routes/staff';
import { index as reservations } from '@/routes/staff/reservations';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const staffNavItems: NavItem[] = [
    {
        title: 'Lane board',
        href: board(),
        icon: Columns3,
    },
    {
        title: 'Reservations',
        href: reservations(),
        icon: CalendarClock,
    },
];

export function AppSidebar() {
    const { auth, version } = usePage().props;
    const navItems = auth.user.role
        ? [...mainNavItems, ...staffNavItems]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
                <p className="px-2 text-xs text-muted-foreground tabular-nums group-data-[collapsible=icon]:hidden">
                    Version {version}
                </p>
            </SidebarFooter>
        </Sidebar>
    );
}
