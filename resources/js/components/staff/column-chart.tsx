import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type ChartSeries = {
    label: string;
    // The class that fills this series' marks, e.g. "bg-chart-1".
    fill: string;
};

export type ChartColumn = {
    key: string;
    // What the column stands for, in full, as its tooltip says it.
    label: string;
    // What is written under the column; null leaves the place empty.
    tick: string | null;
    // One value per series, in the order the series are given.
    values: number[];
};

/**
 * The top of the value axis: the smallest round, even number that fits the
 * tallest column, so the line halfway up is a whole number too.
 */
function axisTop(tallest: number): number {
    const magnitude = 10 ** Math.floor(Math.log10(Math.max(tallest, 1)));

    for (const multiple of [2, 4, 6, 8, 10, 20]) {
        if (multiple * magnitude >= tallest) {
            return multiple * magnitude;
        }
    }

    return tallest;
}

/**
 * A chart of whole numbers as columns, one per day or hour. With more than
 * one series the values are stacked and a legend says which is which.
 * Pointing at a column, or moving to it with the keyboard, gives its numbers.
 */
export function ColumnChart({
    series,
    columns,
    empty,
}: {
    series: ChartSeries[];
    columns: ChartColumn[];
    // What to say in place of the chart while every value is zero.
    empty: string;
}) {
    const totals = columns.map((column) =>
        column.values.reduce((sum, value) => sum + value, 0),
    );
    const tallest = Math.max(0, ...totals);

    if (tallest === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    const top = axisTop(tallest);

    return (
        <div className="space-y-3">
            {series.length > 1 && (
                <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {series.map((item) => (
                        <li
                            key={item.label}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'size-2.5 rounded-[2px]',
                                    item.fill,
                                )}
                            />
                            {item.label}
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex gap-3">
                <div
                    aria-hidden
                    className="flex h-40 flex-col justify-between text-right text-xs text-muted-foreground tabular-nums"
                >
                    <span className="-translate-y-1/2">
                        {top.toLocaleString()}
                    </span>
                    <span>{(top / 2).toLocaleString()}</span>
                    <span className="translate-y-1/2">0</span>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="relative h-40 border-b border-foreground/25">
                        <div
                            aria-hidden
                            className="absolute inset-x-0 top-0 border-t"
                        />
                        <div
                            aria-hidden
                            className="absolute inset-x-0 top-1/2 border-t"
                        />

                        <div className="relative flex h-full">
                            {columns.map((column, index) => {
                                // The highest series with anything in it
                                // carries the column's rounded end.
                                const last = column.values.findLastIndex(
                                    (value) => value > 0,
                                );

                                return (
                                    <Tooltip key={column.key}>
                                        <TooltipTrigger asChild>
                                            <button
                                                type="button"
                                                aria-label={`${column.label}: ${series
                                                    .map(
                                                        (item, at) =>
                                                            `${column.values[at]} ${item.label.toLowerCase()}`,
                                                    )
                                                    .join(', ')}`}
                                                className="group flex h-full min-w-0 flex-1 justify-center rounded-sm px-px outline-none hover:bg-muted focus-visible:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <span className="flex h-full w-full max-w-6 flex-col-reverse gap-0.5">
                                                    {column.values.map(
                                                        (value, at) =>
                                                            value > 0 && (
                                                                <span
                                                                    key={
                                                                        series[
                                                                            at
                                                                        ].label
                                                                    }
                                                                    className={cn(
                                                                        'min-h-0.5 w-full group-hover:opacity-80 group-focus-visible:opacity-80',
                                                                        series[
                                                                            at
                                                                        ].fill,
                                                                        at ===
                                                                            last &&
                                                                            'rounded-t-[4px]',
                                                                    )}
                                                                    style={{
                                                                        height: `${(value / top) * 100}%`,
                                                                    }}
                                                                />
                                                            ),
                                                    )}
                                                </span>
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p className="font-medium">
                                                {column.label}
                                            </p>
                                            {series.map((item, at) => (
                                                <p
                                                    key={item.label}
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <span
                                                        aria-hidden
                                                        className={cn(
                                                            'h-0.5 w-3 rounded-full',
                                                            item.fill,
                                                        )}
                                                    />
                                                    <span className="font-semibold tabular-nums">
                                                        {column.values[at]}
                                                    </span>
                                                    <span className="opacity-80">
                                                        {item.label}
                                                    </span>
                                                </p>
                                            ))}
                                            {series.length > 1 && (
                                                <p className="opacity-80">
                                                    {totals[index]} in all
                                                </p>
                                            )}
                                        </TooltipContent>
                                    </Tooltip>
                                );
                            })}
                        </div>
                    </div>

                    <div aria-hidden className="flex h-6 pt-1.5">
                        {columns.map((column) => (
                            <div
                                key={column.key}
                                className="relative min-w-0 flex-1"
                            >
                                {column.tick && (
                                    <span className="absolute left-1/2 -translate-x-1/2 text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                        {column.tick}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
