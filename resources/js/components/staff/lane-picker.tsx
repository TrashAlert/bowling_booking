import type { LaneOption } from '@/types';

/**
 * Tick boxes for the lanes of a reservation, submitted as "lane_ids[]". A lane
 * that isn't free for the chosen time is shown but can't be ticked, and is
 * left out of the form even if it was ticked before the time changed.
 */
export function LanePicker({
    lanes,
    selected,
    onChange,
    loading,
}: {
    lanes: LaneOption[] | undefined;
    selected: number[];
    onChange: (selected: number[]) => void;
    loading: boolean;
}) {
    if (lanes === undefined) {
        return (
            <div
                className="grid animate-pulse grid-cols-4 gap-2"
                aria-label="Checking which lanes are free"
            >
                {Array.from({ length: 8 }, (_, index) => (
                    <div key={index} className="h-11 rounded-lg bg-muted" />
                ))}
            </div>
        );
    }

    if (lanes.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                There are no lanes yet.
            </p>
        );
    }

    const toggle = (id: number) =>
        onChange(
            selected.includes(id)
                ? selected.filter((other) => other !== id)
                : [...selected, id],
        );

    return (
        <div
            className="grid grid-cols-4 gap-2 aria-busy:opacity-60"
            aria-busy={loading}
        >
            {lanes.map((lane) => (
                <label
                    key={lane.id}
                    className="flex h-11 cursor-pointer flex-col items-center justify-center rounded-lg border text-sm leading-tight font-medium tabular-nums has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 has-disabled:cursor-not-allowed has-disabled:border-dashed has-disabled:text-muted-foreground"
                >
                    <input
                        type="checkbox"
                        name="lane_ids[]"
                        value={lane.id}
                        checked={lane.available && selected.includes(lane.id)}
                        onChange={() => toggle(lane.id)}
                        disabled={!lane.available}
                        className="sr-only"
                    />
                    Lane {lane.number}
                    {(!lane.available || lane.hasBumpers) && (
                        <span className="text-[0.65rem] font-normal">
                            {lane.available ? 'Bumpers' : 'Not free'}
                        </span>
                    )}
                </label>
            ))}
        </div>
    );
}
