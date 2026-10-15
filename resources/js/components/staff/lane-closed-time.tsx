import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DashboardAnalytics } from '@/types';

const REPAIR = { label: 'Repair', fill: 'bg-chart-1' };
const WORK = { label: 'Re-oil and maintenance', fill: 'bg-chart-2' };

/**
 * How long each lane was out of use, as one bar per lane split into time
 * under repair and time closed for a short job. The total is written at the
 * end of each bar; pointing at it, or moving to it with the keyboard, gives
 * the split.
 */
export function LaneClosedTime({
    rows,
}: {
    rows: DashboardAnalytics['closedTime'];
}) {
    if (rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No lane was closed in these days.
            </p>
        );
    }

    const longest = Math.max(
        ...rows.map((row) => row.repairMinutes + row.workMinutes),
    );

    return (
        <div className="space-y-3">
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {[REPAIR, WORK].map((series) => (
                    <li
                        key={series.label}
                        className="flex items-center gap-1.5"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'size-2.5 rounded-[2px]',
                                series.fill,
                            )}
                        />
                        {series.label}
                    </li>
                ))}
            </ul>

            <ul className="space-y-1">
                {rows.map((row) => {
                    const total = row.repairMinutes + row.workMinutes;
                    const segments = [
                        { ...REPAIR, minutes: row.repairMinutes },
                        { ...WORK, minutes: row.workMinutes },
                    ].filter((segment) => segment.minutes > 0);

                    return (
                        <li key={row.number}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label={`Lane ${row.number}: ${formatDuration(total)} out of use, ${formatDuration(row.repairMinutes)} under repair and ${formatDuration(row.workMinutes)} for re-oil and maintenance`}
                                        className="group grid w-full grid-cols-[4rem_minmax(0,1fr)_5rem] items-center gap-3 rounded-sm py-1 text-left text-sm outline-none hover:bg-muted focus-visible:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <span>Lane {row.number}</span>
                                        <span className="flex h-4 gap-0.5">
                                            {segments.map((segment, at) => (
                                                <span
                                                    key={segment.label}
                                                    className={cn(
                                                        'h-full min-w-0.5 group-hover:opacity-80 group-focus-visible:opacity-80',
                                                        segment.fill,
                                                        at ===
                                                            segments.length -
                                                                1 &&
                                                            'rounded-r-[4px]',
                                                    )}
                                                    style={{
                                                        width: `${(segment.minutes / longest) * 100}%`,
                                                    }}
                                                />
                                            ))}
                                        </span>
                                        <span className="text-right text-muted-foreground tabular-nums">
                                            {formatDuration(total)}
                                        </span>
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p className="font-medium">
                                        Lane {row.number}
                                    </p>
                                    {[
                                        {
                                            ...REPAIR,
                                            minutes: row.repairMinutes,
                                        },
                                        { ...WORK, minutes: row.workMinutes },
                                    ].map((series) => (
                                        <p
                                            key={series.label}
                                            className="flex items-center gap-1.5"
                                        >
                                            <span
                                                aria-hidden
                                                className={cn(
                                                    'h-0.5 w-3 rounded-full',
                                                    series.fill,
                                                )}
                                            />
                                            <span className="font-semibold tabular-nums">
                                                {formatDuration(series.minutes)}
                                            </span>
                                            <span className="opacity-80">
                                                {series.label}
                                            </span>
                                        </p>
                                    ))}
                                </TooltipContent>
                            </Tooltip>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
