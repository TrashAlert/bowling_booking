import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, home } from '@/routes';

// What each error tells the person who hit it, in plain words.
const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'You can’t open this page',
        description: 'Your account doesn’t have access to it.',
    },
    404: {
        title: 'We can’t find that page',
        description:
            'The link may be mistyped or out of date. If you joined the waitlist on this phone, the front page leads back to your place in line.',
    },
    419: {
        title: 'This page timed out',
        description:
            'It was open for a long time. Go back, reload the page and try again.',
    },
    429: {
        title: 'Too many tries',
        description: 'Please wait a minute, then go back and try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        description:
            'Please try again in a moment. If it keeps happening, ask our staff at the counter.',
    },
    503: {
        title: 'We’ll be right back',
        description:
            'We are doing some maintenance. Please try again in a few minutes.',
    },
};

// The errors that clear up by trying the same thing again.
const TRY_AGAIN = [419, 429];

/**
 * Shown in place of Laravel's own error pages, inside the customer pages'
 * frame, so a mistyped link or a busy moment still looks like the venue's
 * site. Staff are led back to their dashboard, everyone else to the front
 * page.
 */
export default function ErrorPage({ status }: { status: number }) {
    // Nobody is signed in on the customer pages, whatever the type says.
    const user = usePage().props.auth.user as { role: string | null } | null;
    const message = messages[status] ?? messages[500];
    const isStaff = user?.role != null;

    return (
        <>
            <Head title={message.title} />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">{message.title}</CardTitle>
                    <CardDescription>{message.description}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                    {TRY_AGAIN.includes(status) && (
                        <Button onClick={() => window.history.back()}>
                            Go back
                        </Button>
                    )}
                    <Button
                        variant={
                            TRY_AGAIN.includes(status) ? 'outline' : 'default'
                        }
                        asChild
                    >
                        <Link href={isStaff ? dashboard() : home()}>
                            {isStaff
                                ? 'Go to the dashboard'
                                : 'Go to the front page'}
                        </Link>
                    </Button>
                    <p className="pt-2 text-center text-xs text-muted-foreground tabular-nums">
                        Error {status}
                    </p>
                </CardContent>
            </Card>
        </>
    );
}
