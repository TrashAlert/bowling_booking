import { ChoicePills } from '@/components/staff/choice-pills';
import {
    defaultSessionLength,
    SessionLengthPicker,
} from '@/components/staff/session-length-picker';
import { formatSessionLength } from '@/lib/format';
import { gameMinutes } from '@/lib/sessions';
import type { SessionChoice } from '@/lib/sessions';
import type { SessionRules } from '@/types';

/**
 * How long a group wants to play, as the venue sells it: a length of time
 * (submitted as "minutes"), a number of games (submitted as "games"), or a
 * switch between the two while it sells both. For games it says how long
 * the lane will be kept, which depends on how many people bowl.
 */
export function SessionChoicePicker({
    session,
    value,
    onChange,
    partySize,
}: {
    session: SessionRules;
    value: SessionChoice;
    onChange: (choice: SessionChoice) => void;
    partySize: number;
}) {
    // What each kind starts at when switched to.
    const firstTime = (): SessionChoice => ({
        kind: 'time',
        minutes: defaultSessionLength(session),
    });
    const firstGames = (): SessionChoice => ({
        kind: 'games',
        games: Math.min(2, session.maxGames),
    });

    return (
        <div className="grid gap-3">
            {session.mode === 'both' && (
                <ChoicePills
                    name="session_kind"
                    className="grid-cols-2"
                    options={[
                        { value: 'time', label: 'By time' },
                        { value: 'games', label: 'By games' },
                    ]}
                    value={value.kind}
                    onChange={(kind) =>
                        kind !== value.kind &&
                        onChange(kind === 'time' ? firstTime() : firstGames())
                    }
                />
            )}

            {value.kind === 'time' ? (
                <SessionLengthPicker
                    session={session}
                    value={value.minutes}
                    onChange={(minutes) => onChange({ kind: 'time', minutes })}
                />
            ) : (
                <>
                    <ChoicePills
                        name="games"
                        className="grid-cols-5"
                        options={Array.from(
                            { length: session.maxGames },
                            (_, index) => ({
                                value: index + 1,
                                label: index + 1,
                                hint: index === 0 ? 'game' : 'games',
                            }),
                        )}
                        value={value.games}
                        onChange={(games) => onChange({ kind: 'games', games })}
                    />
                    <p className="text-xs text-muted-foreground">
                        The lane is kept for about{' '}
                        {formatSessionLength(
                            gameMinutes(session, value.games, partySize),
                        )}{' '}
                        for{' '}
                        {partySize === 1
                            ? '1 person'
                            : `${partySize || 1} people`}
                        , at {session.minutesPerPlayerGame} minutes per person
                        per game.
                    </p>
                </>
            )}
        </div>
    );
}
