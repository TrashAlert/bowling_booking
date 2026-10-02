// Runs in the background on a customer's phone, even with the waitlist page
// closed or the phone locked. Its only job is to show the notification the
// server pushes when the party is called, and to open the party's page when
// the notification is tapped.

self.addEventListener('push', (event) => {
    const message = event.data ? event.data.json() : {};

    event.waitUntil(
        self.registration.showNotification(message.title ?? 'It is your turn', {
            body: message.body ?? 'Go to the counter now.',
            data: { url: message.url ?? '/' },
            // One notification per call: a repeat replaces the first.
            tag: 'waitlist-call',
            renotify: true,
            // Stays on screen until the customer deals with it.
            requireInteraction: true,
            vibrate: [400, 200, 400, 200, 400, 200, 800],
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    const url = new URL(event.notification.data.url, self.location.origin).href;

    event.notification.close();

    // Bring the party's page forward if it is still open; otherwise open it.
    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                const open = windows.find((window) => window.url === url);

                return open ? open.focus() : self.clients.openWindow(url);
            }),
    );
});
