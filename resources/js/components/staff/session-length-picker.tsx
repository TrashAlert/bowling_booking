import { formatSessionLength } from '@/lib/format';
import type { SessionRules } from '@/types';

// The length that is ticked to begin with, if the rules allow it.
const DEFAULT_SESSION_MINUTES = 60;

/**
 * The lengths a session can be, from one step up to the limit.
 */
export function sessionLengths(session: SessionRules): number[] {
    return Array.from(
        { length: Math.floor(session.maxMinutes / session.stepMinutes) },
        (_, index) => (index + 1) * session.stepMinutes,
    );
}

export function defaultSessionLength(session: SessionRules): number {
    const lengths = sessionLengths(session);

    return lengths.includes(DEFAULT_SESSION_MINUTES)
        ? DEFAULT_SESSION_MINUTES
        : lengths[0];
}

/**
 * Buttons for choosing how long a session lasts, submitted as "minutes".
 * Pass value and onChange when the form needs to react to the choice;
 * leave them out and it keeps its own state.
 */
export function SessionLengthPicker({
    session,
    value,
    onChange,
}: {
    session: SessionRules;
    value?: number;
    onChange?: (minutes: number) => void;
}) {
    const initial = defaultSessionLength(session);

    return (
        <div className="grid grid-cols-4 gap-2">
            {sessionLengths(session).map((minutes) => (
                <label
                    key={minutes}
                    className="flex h-11 cursor-pointer items-center justify-center rounded-lg border text-sm font-medium tabular-nums has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                >
                    <input
                        type="radio"
                        name="minutes"
                        value={minutes}
                        required
                        className="sr-only"
                        {...(value === undefined
                            ? { defaultChecked: minutes === initial }
                            : {
                                  checked: minutes === value,
                                  onChange: () => onChange?.(minutes),
                              })}
                    />
                    {formatSessionLength(minutes)}
                </label>
            ))}
        </div>
    );
}
