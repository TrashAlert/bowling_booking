import { useEffect, useRef, useState } from 'react';

/**
 * The current time in milliseconds, ticking every second and aligned to the
 * server's clock so countdowns stay right on a device whose own clock is off.
 * Pass the latest server time each time the page data refreshes.
 */
export function useServerClock(serverNow: string): number {
    const offset = useRef(0);
    const [now, setNow] = useState(() => Date.parse(serverNow));

    useEffect(() => {
        offset.current = Date.parse(serverNow) - Date.now();
    }, [serverNow]);

    useEffect(() => {
        const interval = window.setInterval(() => {
            setNow(Date.now() + offset.current);
        }, 1000);

        return () => window.clearInterval(interval);
    }, []);

    return now;
}
