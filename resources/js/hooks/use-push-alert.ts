import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

// The script that shows the notification while the page is closed.
const SERVICE_WORKER = '/sw.js';

export type PushAlertState =
    // This browser can't show notifications from a website, or they aren't
    // set up on the server.
    | 'unsupported'
    | 'off'
    | 'working'
    | 'on'
    // The customer, or their browser's settings, said no.
    | 'blocked'
    | 'failed';

/**
 * The server's public key as the bytes the browser wants. It arrives in
 * URL-safe base64.
 */
function keyBytes(publicKey: string): Uint8Array<ArrayBuffer> {
    const padded = publicKey.padEnd(
        publicKey.length + ((4 - (publicKey.length % 4)) % 4),
        '=',
    );
    const binary = window.atob(padded.replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

/**
 * This phone's address at its push service, asking for a new one if it has
 * none. An address made with an older server key can't be reused, so it is
 * dropped and asked for again.
 */
async function subscription(publicKey: string): Promise<PushSubscription> {
    const registration = await navigator.serviceWorker.register(SERVICE_WORKER);

    await navigator.serviceWorker.ready;

    const options = {
        userVisibleOnly: true,
        applicationServerKey: keyBytes(publicKey),
    };

    try {
        return await registration.pushManager.subscribe(options);
    } catch {
        await (await registration.pushManager.getSubscription())?.unsubscribe();

        return registration.pushManager.subscribe(options);
    }
}

/**
 * Lets a waiting party have its phone notified when it is called, even with
 * the phone locked or the page closed. Turning it on asks the phone for
 * permission, then hands the phone's push address to the server at saveUrl.
 *
 * Whether it is on comes from the server (isOn), so it survives a reload.
 * publicKey is null while notifications aren't set up on the server.
 */
export function usePushAlert(
    publicKey: string | null,
    isOn: boolean,
    saveUrl: string,
): { state: PushAlertState; turnOn: () => void } {
    const [supported] = useState(
        () =>
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window,
    );
    const [progress, setProgress] = useState<
        'idle' | 'working' | 'blocked' | 'failed'
    >(() =>
        supported && Notification.permission === 'denied' ? 'blocked' : 'idle',
    );

    const turnOn = useCallback(() => {
        if (publicKey === null) {
            return;
        }

        setProgress('working');

        Notification.requestPermission()
            .then(async (permission) => {
                if (permission !== 'granted') {
                    setProgress(permission === 'denied' ? 'blocked' : 'idle');

                    return;
                }

                const address = (await subscription(publicKey)).toJSON();

                router.put(
                    saveUrl,
                    {
                        endpoint: address.endpoint ?? '',
                        keys: address.keys ?? {},
                    },
                    {
                        only: ['ticket'],
                        preserveState: true,
                        preserveScroll: true,
                        onSuccess: () => setProgress('idle'),
                        onError: () => setProgress('failed'),
                    },
                );
            })
            .catch(() => setProgress('failed'));
    }, [publicKey, saveUrl]);

    let state: PushAlertState = 'off';

    if (!supported || publicKey === null) {
        state = 'unsupported';
    } else if (isOn && progress === 'idle') {
        state = 'on';
    } else if (progress !== 'idle') {
        state = progress;
    }

    return { state, turnOn };
}
