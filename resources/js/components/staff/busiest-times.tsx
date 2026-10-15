import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatHour, formatWeekday } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DashboardAnalytics } from '@/types';

// From fewest sessions to most. An hour with none is left blank.
const LEVELS = ['bg-heat-1', 'bg-heat-2', 'bg-heat-3', 'bg-heat-4'];

/**
 * When the venue is busy: a grid of the days of the week against the hours
 * of the day, each square shaded by how many sessions start then on an
 * average day. Pointing at a square, or moving to it with the keyboard,
 * gives its numbers.
 */
export function BusiestTimes({
    hours,
    rows,
}: {
    // The hours across the grid, with what is written under each (null
    // leaves the place empty).
    hours: { hour: number; tick: string | null }[];
    rows: DashboardAnalytics['byWeekday'];
}) {
    const averages = rows.map((row) =>
        row.sessions.map((count) => (row.days > 0 ? count / row.days : 0)),
    );
    const busiest = Math.max(0, ...averages.flat());

    if (busiest === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No sessions were played in these days.
            </p>
        );
    }

    const level = (average: number) =>
        average === 0
            ? 'bg-muted'
            : LEVELS[Math.min(3, Math.ceil((average / busiest) * 4) - 1)];

    return (
        <div className="space-y-3">
            <div
                className="grid gap-0.5"
                style={{
                    gridTemplateColumns: `auto repeat(${hours.length}, minmax(0, 1fr))`,
                }}
            >
                {rows.map((row, at) => (
                    <div key={row.weekday} className="contents">
                        <span
                            aria-hidden
                            className="pr-2 text-xs leading-7 text-muted-foreground"
                        >
                            {formatWeekday(row.weekday)}
                        </span>

                        {hours.map(({ hour }, index) => {
                            const count = row.sessions[index];
                            const average = averages[at][index];
                            const when = `${formatWeekday(row.weekday, true)}, ${formatHour(hour)} to ${formatHour((hour + 1) % 24)}`;
                            const sessions = `${count} ${count === 1 ? 'session' : 'sessions'}`;
                            const detail =
                                row.days <= 1
                                    ? sessions
                                    : `${average.toFixed(1)} on average, ${sessions} over ${row.days} ${formatWeekday(row.weekday, true)}s`;

                            return (
                                <Tooltip key={hour}>
                                    <TooltipTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label={`${when}: ${detail}`}
                                            className={cn(
                                                'h-7 min-w-0 rounded-[3px] outline-none hover:opacity-80 focus-visible:ring-2 focus-visible:ring-ring',
                                                level(average),
                                            )}
                                        />
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p className="font-medium">{when}</p>
                                        <p>
                                            <span className="font-semibold">
                                                {row.days <= 1
                                                    ? count
                                                    : average.toFixed(1)}
                                            </span>{' '}
                                            <span className="opacity-80">
                                                {row.days <= 1
                                                    ? count === 1
                                                        ? 'session'
                                                        : 'sessions'
                                                    : `on average · ${sessions} over ${row.days} ${formatWeekday(row.weekday, true)}s`}
                                            </span>
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            );
                        })}
                    </div>
                ))}

                <span aria-hidden />
                {hours.map(({ hour, tick }) => (
                    <div
                        key={hour}
                        aria-hidden
                        className="relative h-5 min-w-0"
                    >
                        {tick && (
                            <span className="absolute top-1 left-1/2 -translate-x-1/2 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                {tick}
                            </span>
                        )}
                    </div>
                ))}
            </div>

            <div
                aria-hidden
                className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <span className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-[2px] bg-muted" />
                    None
                </span>
                <span className="flex items-center gap-1.5">
                    Fewer
                    {LEVELS.map((fill) => (
                        <span
                            key={fill}
                            className={cn('size-2.5 rounded-[2px]', fill)}
                        />
                    ))}
                    More
                </span>
            </div>
        </div>
    );
}
