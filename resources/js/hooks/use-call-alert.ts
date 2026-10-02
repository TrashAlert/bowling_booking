import { useCallback, useEffect, useRef, useState } from 'react';

// How many times the two-note chime sounds when a party is called.
const CHIMES = 3;
const SECONDS_BETWEEN_CHIMES = 1.2;

// Buzz and pause lengths in milliseconds, ending on a long buzz.
const VIBRATION = [400, 200, 400, 200, 400, 200, 800];

/**
 * Play one note that fades in and out, so it doesn't click.
 */
function note(
    context: AudioContext,
    startAt: number,
    frequency: number,
    seconds: number,
): void {
    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(0.5, startAt + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + seconds);

    oscillator.connect(gain).connect(context.destination);
    oscillator.start(startAt);
    oscillator.stop(startAt + seconds + 0.05);
}

/**
 * Play a rising two-note chime the given number of times.
 */
function chime(context: AudioContext, times: number): void {
    const startAt = context.currentTime + 0.05;

    for (let index = 0; index < times; index++) {
        const at = startAt + index * SECONDS_BETWEEN_CHIMES;

        note(context, at, 880, 0.25);
        note(context, at + 0.3, 1175, 0.45);
    }
}

/**
 * Alerts a waiting party the moment it is called: the phone vibrates where
 * it can, and chimes once the party has turned the sound on.
 *
 * A browser only lets a page make sound after the person has tapped
 * something, so the sound has to be turned on with a tap; that tap plays one
 * chime as a check. Neither alert can reach a phone whose screen is locked or
 * that has another app in front, because the page stops running then.
 */
export function useCallAlert(isCalled: boolean): {
    canPlaySound: boolean;
    soundOn: boolean;
    turnSoundOn: () => void;
} {
    const context = useRef<AudioContext | null>(null);
    const wasCalled = useRef(isCalled);
    const [soundOn, setSoundOn] = useState(false);
    const [canPlaySound] = useState(
        () => typeof window !== 'undefined' && 'AudioContext' in window,
    );

    const turnSoundOn = useCallback(() => {
        context.current ??= new AudioContext();

        void context.current.resume();
        chime(context.current, 1);
        setSoundOn(true);
    }, []);

    useEffect(() => {
        if (isCalled && !wasCalled.current) {
            if ('vibrate' in navigator) {
                navigator.vibrate(VIBRATION);
            }

            if (context.current) {
                void context.current.resume();
                chime(context.current, CHIMES);
            }
        }

        wasCalled.current = isCalled;
    }, [isCalled]);

    useEffect(
        () => () => {
            void context.current?.close();
        },
        [],
    );

    return { canPlaySound, soundOn, turnSoundOn };
}
