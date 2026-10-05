import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    formatSessionLength,
    formatShortDate,
    formatTime,
    formatTimeOfDay,
} from '@/lib/format';
import { home } from '@/routes';

type SentRequest = {
    name: string;
    phone: string;
    partySize: number;
    minutes: number;
    startsAt: string;
    // When we can call, as "HH:MM"; both null for any time.
    contactFrom: string | null;
    contactUntil: string | null;
};

/**
 * Shown straight after a customer sends a reservation request. Nothing is
 * booked yet: staff call them to confirm.
 */
export default function RequestSent({ request }: { request: SentRequest }) {
    const when =
        request.contactFrom && request.contactUntil
            ? `between ${formatTimeOfDay(request.contactFrom)} and ${formatTimeOfDay(request.contactUntil)}`
            : 'soon';

    const details = [
        ['Date', formatShortDate(request.startsAt)],
        [
            'Time',
            `${formatTime(request.startsAt)} (${formatSessionLength(request.minutes)})`,
        ],
        [
            'Group',
            `${request.partySize} ${request.partySize === 1 ? 'person' : 'people'}`,
        ],
    ];

    return (
        <>
            <Head title="Request sent" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">
                        Thank you, {request.name}
                    </CardTitle>
                    <CardDescription>
                        We have your request. We will call you on{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {request.phone}
                        </span>{' '}
                        {when} to confirm it.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <dl className="divide-y rounded-lg border text-sm">
                        {details.map(([label, value]) => (
                            <div
                                key={label}
                                className="flex justify-between gap-4 p-3"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="text-right font-medium tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <p className="text-sm text-muted-foreground">
                        Your reservation is not confirmed until we have spoken
                        to you.
                    </p>

                    <Button variant="outline" className="w-full" asChild>
                        <Link href={home()}>Back to the front page</Link>
                    </Button>
                </CardContent>
            </Card>
        </>
    );
}
