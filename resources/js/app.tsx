import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PublicLayout from '@/layouts/public-layout';
import SettingsLayout from '@/layouts/settings/layout';

// The business name set under Settings, which the server writes into the page.
const appName = () =>
    document.querySelector<HTMLMetaElement>('meta[name="app-name"]')?.content ||
    import.meta.env.VITE_APP_NAME ||
    'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    layout: (name) => {
        switch (true) {
            // Pages for customers have no staff sidebar.
            case name === 'welcome':
                return null;
            case name.startsWith('waitlist/'):
            case name.startsWith('reserve/'):
            // Error pages may come before anyone has signed in.
            case name === 'error':
                return PublicLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster position="top-center" />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
