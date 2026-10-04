import { Form, Head, usePoll } from '@inertiajs/react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import { AddWalkInDialog } from '@/components/staff/add-walk-in-dialog';
import { LaneCard } from '@/components/staff/lane-card';
import { ReservationsPanel } from '@/components/staff/reservations-panel';
import { WaitlistPanel } from '@/components/staff/waitlist-panel';
import { Button } from '@/components/ui/button';
import { useServerClock } from '@/hooks/use-server-clock';
import { board } from '@/routes/staff';
import type {
    ClosureOptions,
    LaneCard as LaneCardData,
    LaneOption,
    ReservationRow,
    SessionRules,
    WaitlistRow,
} from '@/types';

type Props = {
    lanes: LaneCardData[];
    waitlist: WaitlistRow[];
    reservations: ReservationRow[];
    session: SessionRules;
    closureOptions: ClosureOptions;
    laneOptions?: LaneOption[];
    serverNow: string;
};

// How often the board refreshes itself. Reverb will replace this later.
const POLL_INTERVAL_MS = 5000;

export default function Board({
    lanes,
    waitlist,
    reservations,
    session,
    closureOptions,
    laneOptions,
    serverNow,
}: Props) {
    usePoll(POLL_INTERVAL_MS, {
        only: [
            'lanes',
            'waitlist',
            'reservations',
            'serverNow',
            'requestsWaiting',
        ],
    });

    const now = useServerClock(serverNow);
    const freeLanes = lanes.filter((lane) => lane.state === 'free').length;

    return (
        <>
            <Head title="Lane board" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Lane board
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {freeLanes} of {lanes.length} lanes free ·{' '}
                            {waitlist.length} in line
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Form
                            {...WaitlistController.callNext.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button variant="outline" disabled={processing}>
                                    Call next now
                                </Button>
                            )}
                        </Form>
                        <AddWalkInDialog session={session} />
                    </div>
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section aria-label="Lanes" className="@container">
                        <div className="grid grid-cols-1 gap-3 @md:grid-cols-2 @2xl:grid-cols-3 @5xl:grid-cols-4">
                            {lanes.map((lane) => (
                                <LaneCard
                                    key={lane.id}
                                    lane={lane}
                                    now={now}
                                    session={session}
                                    closureOptions={closureOptions}
                                />
                            ))}
                        </div>
                    </section>

                    <div className="flex flex-col gap-4">
                        <WaitlistPanel waitlist={waitlist} now={now} />
                        <ReservationsPanel
                            reservations={reservations}
                            laneOptions={laneOptions}
                            now={now}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

Board.layout = {
    breadcrumbs: [
        {
            title: 'Lane board',
            href: board(),
        },
    ],
};
