import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, login, reserve } from '@/routes';
import { join } from '@/routes/waitlist';

// What customers can do from this page. Joining the waitlist works, with a
// stand-in for the deposit payment. Reserving opens a sample page that saves
// nothing.
const actions = [
    {
        icon: Users,
        title: 'Join the waitlist',
        description:
            'Walking in? Add your group to the line and we will call you when a lane is ready.',
        action: 'Join the waitlist',
        badge: 'Trial',
        note: 'A deposit is paid to join online.',
        href: join(),
    },
    {
        icon: CalendarClock,
        title: 'Reserve a lane',
        description:
            'Planning ahead? Pick a date and time and we will keep a lane for you.',
        action: 'Make a reservation',
        badge: 'Sample',
        note: 'For now, please ask our staff at the counter.',
        href: reserve(),
    },
];

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-4xl items-center justify-between gap-4 p-4 sm:p-6">
                    <span className="truncate font-semibold tracking-tight">
                        {name}
                    </span>
                    <Button variant="ghost" size="sm" asChild>
                        {auth.user ? (
                            <Link href={dashboard()}>Staff area</Link>
                        ) : (
                            <Link href={login()}>Staff login</Link>
                        )}
                    </Button>
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center gap-10 p-4 sm:p-6">
                    <div className="text-center">
                        <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            {name}
                        </h1>
                        <p className="mt-3 text-lg text-muted-foreground">
                            Come and bowl. Walk in, or book a lane ahead.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {actions.map((item) => (
                            <Card key={item.title}>
                                <CardHeader>
                                    <item.icon
                                        aria-hidden
                                        className="mb-2 size-6 text-muted-foreground"
                                    />
                                    <CardTitle className="flex items-center gap-2">
                                        {item.title}
                                        <Badge variant="secondary">
                                            {item.badge}
                                        </Badge>
                                    </CardTitle>
                                    <CardDescription>
                                        {item.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="text-sm text-muted-foreground">
                                    {item.note}
                                </CardContent>
                                <CardFooter>
                                    <Button className="w-full" asChild>
                                        <Link href={item.href}>
                                            {item.action}
                                        </Link>
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                </main>

                <footer className="p-4 text-center text-sm text-muted-foreground sm:p-6">
                    © {new Date().getFullYear()} {name}
                </footer>
            </div>
        </>
    );
}
