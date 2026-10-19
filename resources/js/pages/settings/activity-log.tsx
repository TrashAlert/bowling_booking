import { Form, Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import ActivityLogController from '@/actions/App/Http/Controllers/Settings/ActivityLogController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { index } from '@/routes/activity-log';

type ActorType = 'user' | 'customer' | 'system' | 'unknown';

type Entry = {
    id: number;
    occurredAt: string;
    actorType: ActorType;
    actorName: string;
    action: string;
    summary: string;
    // What changed, each as [before, after].
    changes: Record<string, [unknown, unknown]> | null;
    ipAddress: string | null;
};

type Filters = {
    from: string | null;
    to: string | null;
    who: string | null;
    kind: string | null;
    search: string | null;
};

const actorLabels: Record<ActorType, string> = {
    user: 'Staff',
    customer: 'Customer',
    system: 'Automatic',
    unknown: 'Unknown',
};

const actorStyles: Record<ActorType, string> = {
    user: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300',
    customer:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    system: 'bg-neutral-200 text-neutral-800 dark:bg-neutral-500/20 dark:text-neutral-300',
    unknown: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300',
};

const SELECT =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

/**
 * A value from a line's changes in words.
 */
function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (Array.isArray(value)) {
        return value.length === 0 ? '—' : value.map(show).join(', ');
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return JSON.stringify(value);
}

function LogEntry({
    entry,
    when,
}: {
    entry: Entry;
    when: Intl.DateTimeFormat;
}) {
    return (
        <li className="grid gap-1 p-4 sm:grid-cols-[9.5rem_minmax(0,1fr)] sm:gap-4">
            <p className="text-sm text-muted-foreground tabular-nums">
                {when.format(new Date(entry.occurredAt))}
            </p>

            <div className="min-w-0 space-y-1">
                <p className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium">{entry.actorName}</span>
                    <Badge
                        className={cn(
                            'border-transparent',
                            actorStyles[entry.actorType],
                        )}
                    >
                        {actorLabels[entry.actorType]}
                    </Badge>
                    {entry.ipAddress && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {entry.ipAddress}
                        </span>
                    )}
                </p>
                <p>{entry.summary}</p>
                {entry.changes && (
                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 text-sm text-muted-foreground">
                        {Object.entries(entry.changes).map(
                            ([field, [before, after]]) => (
                                <div key={field} className="contents">
                                    <dt>{field}</dt>
                                    <dd className="break-words">
                                        {show(before)}{' '}
                                        <span aria-label="changed to">→</span>{' '}
                                        <span className="text-foreground">
                                            {show(after)}
                                        </span>
                                    </dd>
                                </div>
                            ),
                        )}
                    </dl>
                )}
                <p className="font-mono text-xs text-muted-foreground">
                    #{entry.id} · {entry.action}
                </p>
            </div>
        </li>
    );
}

/**
 * Where an admin reads the activity log: everything staff, customers and the
 * app itself did, newest first. Nothing here can be changed or removed.
 */
export default function ActivityLogPage({
    entries,
    page,
    filters,
    kinds,
    people,
    retentionMonths,
    timezone,
}: {
    entries: Entry[];
    page: { current: number; last: number; total: number };
    filters: Filters;
    kinds: Record<string, string>;
    people: { value: string; label: string }[];
    retentionMonths: number;
    timezone: string;
}) {
    // Times are shown on the venue's clock, whatever the device is set to.
    const when = new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });

    const pageLink = (number: number) =>
        index({
            query: {
                ...Object.fromEntries(
                    Object.entries(filters).filter(([, value]) => value),
                ),
                page: number,
            },
        });

    return (
        <>
            <Head title="Activity log" />

            <h1 className="sr-only">Activity log</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Activity log"
                    description={`Everything staff, customers and the app itself did. Lines can't be changed or removed, and are kept for ${retentionMonths} months. Times are in ${timezone}.`}
                />

                <Form
                    {...ActivityLogController.verify.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <ShieldCheck aria-hidden />
                            {processing
                                ? 'Checking every line…'
                                : 'Check nothing has been changed'}
                        </Button>
                    )}
                </Form>

                <Form
                    {...ActivityLogController.index.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                >
                    {({ errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="log-from">From</Label>
                                <Input
                                    id="log-from"
                                    name="from"
                                    type="date"
                                    defaultValue={filters.from ?? ''}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="log-to">To</Label>
                                <Input
                                    id="log-to"
                                    name="to"
                                    type="date"
                                    defaultValue={filters.to ?? ''}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="log-who">Who</Label>
                                <select
                                    id="log-who"
                                    name="who"
                                    defaultValue={filters.who ?? ''}
                                    className={SELECT}
                                >
                                    <option value="">Anyone</option>
                                    <option value="customer">Customers</option>
                                    <option value="system">
                                        Automatic (the app itself)
                                    </option>
                                    <option value="unknown">
                                        Unknown (failed logins)
                                    </option>
                                    {people.map((person) => (
                                        <option
                                            key={person.value}
                                            value={person.value}
                                        >
                                            {person.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="log-kind">What</Label>
                                <select
                                    id="log-kind"
                                    name="kind"
                                    defaultValue={filters.kind ?? ''}
                                    className={SELECT}
                                >
                                    <option value="">Everything</option>
                                    {Object.entries(kinds).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="log-search">Words</Label>
                                <Input
                                    id="log-search"
                                    name="search"
                                    type="search"
                                    maxLength={100}
                                    defaultValue={filters.search ?? ''}
                                    placeholder="A name, a lane, a setting…"
                                />
                            </div>
                            {(errors.from || errors.to) && (
                                <p className="text-sm text-destructive sm:col-span-2">
                                    {errors.to ?? errors.from}
                                </p>
                            )}
                            <div className="flex gap-2 sm:col-span-2">
                                <Button type="submit">Show</Button>
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Clear</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <p className="text-sm text-muted-foreground">
                    {page.total.toLocaleString()}{' '}
                    {page.total === 1 ? 'line' : 'lines'}
                    {page.last > 1 && ` · page ${page.current} of ${page.last}`}
                </p>

                {entries.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Nothing matches.
                    </p>
                ) : (
                    <ol className="divide-y rounded-xl border bg-card text-card-foreground shadow-sm">
                        {entries.map((entry) => (
                            <LogEntry
                                key={entry.id}
                                entry={entry}
                                when={when}
                            />
                        ))}
                    </ol>
                )}

                {page.last > 1 && (
                    <nav
                        aria-label="Pages"
                        className="flex items-center justify-between gap-2"
                    >
                        {page.current > 1 ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={pageLink(page.current - 1)}
                                    preserveScroll={false}
                                >
                                    Newer
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {page.current < page.last && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={pageLink(page.current + 1)}>
                                    Older
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

ActivityLogPage.layout = {
    breadcrumbs: [
        {
            title: 'Activity log',
            href: index(),
        },
    ],
};
