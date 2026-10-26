import type { SessionRules } from '@/types';

// What a group is booking: a length of time, or a number of games.
export type SessionChoice =
    | { kind: 'time'; minutes: number }
    | { kind: 'games'; games: number };

// The number of games ticked to begin with, if the rules allow it.
const DEFAULT_GAMES = 2;

/**
 * How long a lane is kept for a number of games, in minutes. The same sum
 * as the server's (App\Services\GameSessions::minutesFor): games times the
 * people on one lane times the minutes one person takes per game, rounded up
 * to a session step. A big group is spread over the lanes it needs.
 */
export function gameMinutes(
    session: SessionRules,
    games: number,
    partySize: number,
): number {
    const people = Math.max(1, partySize || 1);
    const lanes = Math.max(1, Math.ceil(people / session.maxPlayersPerLane));
    const perLane = Math.ceil(people / lanes);
    const minutes = games * perLane * session.minutesPerPlayerGame;

    return Math.max(
        session.stepMinutes,
        Math.ceil(minutes / session.stepMinutes) * session.stepMinutes,
    );
}

/**
 * How long the lane is kept for a choice, in minutes.
 */
export function choiceMinutes(
    session: SessionRules,
    choice: SessionChoice,
    partySize: number,
): number {
    return choice.kind === 'time'
        ? choice.minutes
        : gameMinutes(session, choice.games, partySize);
}

/**
 * What a form starts with: a length of time, unless the venue only sells
 * games.
 */
export function defaultChoice(
    session: SessionRules,
    defaultMinutes: number,
): SessionChoice {
    return session.mode === 'games'
        ? { kind: 'games', games: Math.min(DEFAULT_GAMES, session.maxGames) }
        : { kind: 'time', minutes: defaultMinutes };
}

/**
 * The choice an existing booking was made with, for a form that changes it.
 */
export function choiceOf(minutes: number, games: number | null): SessionChoice {
    return games === null
        ? { kind: 'time', minutes }
        : { kind: 'games', games };
}
