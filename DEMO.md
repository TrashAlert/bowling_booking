# The demo: Strike Zone Bowling

A public copy of the app that bowling alley owners can try for themselves. It runs as **Strike Zone Bowling**, a made-up venue with an evening in full swing and four weeks of history. Every visitor shares it, and it starts afresh every day at 9:00 AM.

This file has two parts:

- [Hosting the demo](#hosting-the-demo): setting it up on a server.
- [Presenting it to an owner](#presenting-it-to-an-owner): a walkthrough to follow in a meeting.

## Hosting the demo

### What the demo does by itself

| When                       | What                                                                                                                                                                                                 |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Every day at 9:00 AM       | `demo:reset` empties the demo database and builds the venue again: lanes in play, a waitlist, reservations, requests, a league night, a lane under repair, and four weeks of history.                |
| Every 2 minutes while open | `demo:liven` plays the customers: called groups arrive and are seated, reservations check in, new groups join the line and reservation requests come in. A few groups miss their call or don't come. |
| Every minute               | The usual jobs: calling the line, no-shows, finished sessions.                                                                                                                                       |

The demo is open from 9:00 AM to 3:00 AM, Malaysian time. Outside those hours the customer pages say it is closed and joining online is off, as at a real venue.

### What visitors can and can't do

- **Logins:** the login page has a button for each. Owner (admin) `owner@demo.test` / `demo-owner`, counter staff `counter@demo.test` / `demo-counter`.
- **A banner on every page** says it is a demo and links between the customer side and the staff side.
- **Turned off,** so no visitor can spoil it for the others: adding, changing or removing user accounts, changing your own name, email or password, and uploading a logo or cover photo.
- **Everything else can be tried,** settings included. The next reset puts it back.

### Setting it up

The demo is a **separate copy** of the app, with its own server or folder, its own `.env`, its own `APP_KEY` and its own database. Never turn demo mode on at a real venue: `demo:reset` empties the database.

You need PHP 8.3 or newer, Composer, Node.js, PostgreSQL, and a domain with HTTPS, e.g. `demo.yourdomain.com`.

1. **Get the code and build it.**

    ```bash
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build
    ```

2. **Create the database.** Its name must contain "demo", or the demo commands refuse to run.

    ```bash
    createdb bowling_demo
    ```

3. **Write `.env`.** Start from `.env.example`, then set:

    ```ini
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://demo.yourdomain.com

    # This copy is the public demo.
    BOWLING_DEMO=true

    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=bowling_demo
    DB_USERNAME=...
    DB_PASSWORD=...

    # Where phones reach the demo, for the walk-in QR codes.
    BOWLING_PUBLIC_URL=https://demo.yourdomain.com

    # Only if a proxy or tunnel sits in front of the app, as in the README.
    # TRUSTED_PROXIES=127.0.0.1
    ```

    Then make a new key: `php artisan key:generate`. Leave `SESSION_SECURE_COOKIE` unset, as in the README.

4. **Notifications (optional).** Run `php artisan push:keys` and copy the two lines into `.env`, with `VAPID_SUBJECT=mailto:you@yourdomain.com`. Without them the notification button is simply hidden. They need HTTPS.

5. **Build the venue for the first time.**

    ```bash
    php artisan demo:reset --force
    ```

6. **Run the scheduler** with a cron entry. Without it the demo is never reset, never kept busy, and the line is never called.

    ```cron
    * * * * * cd /path/to/the/demo && php artisan schedule:run >> /dev/null 2>&1
    ```

7. **Point the web server at `public/`.** Unlike a real venue, the demo has **no** gate in front of the staff pages: visitors are meant to log in and try them. Everything else in the README's "Going live" applies.

8. **Check it.** Open the site on a phone and on a computer:
    - The amber demo banner shows on every page.
    - Join the waitlist from the phone, log in as the owner on the computer, and see the group on the lane board.
    - The dashboard's "Needs attention" doesn't say the scheduler has stopped.

### Running it day to day

- **Reset it by hand** at any time, for example just before a meeting: `php artisan demo:reset --force`. It takes a few seconds, and signs everyone out.
- **If you change `.env`** after caching the config (`php artisan config:cache`), cache it again.
- **To update the demo** after changing the app: pull, build as in step 1, `php artisan migrate --force`, then reset it.

## Presenting it to an owner

About 20 minutes. Use a laptop for the staff side and your phone for the customer side, so the owner sees both at once.

### Before the meeting

- Reset the demo 10 to 15 minutes before, so the evening is fresh: `php artisan demo:reset --force`. Keep the scheduler running.
- On the laptop, open the lane board, logged in as the owner. On the phone, open the front page.
- On an Android phone, turn notifications on for the site once beforehand, so the browser doesn't ask in the middle of the demo. On an iPhone, notifications don't work yet (see below), so show the chime instead.

### 1. The problem (2 minutes)

Ask how their customers join the line today. Usually: they queue at the counter, get no idea of the wait, and walk away. Staff juggle a paper list and phone calls for reservations.

"This puts the line on the customer's phone, and the whole venue on one screen at the counter."

### 2. The customer's side (5 minutes, on the phone)

1. **The front page.** It shows how many lanes are free right now and how many groups are waiting, the opening hours, and an announcement banner. Mention the business's own name, colour and photo.
2. **Join the waitlist.** Fill in a name, a phone number and the group size, then choose by time or by games. Point out the estimated wait, which updates live. When every lane is busy, a deposit is asked for; say the Pay button is a stand-in until a payment provider such as FPX is connected.
3. **The group's own page.** It shows the place in line and the estimated wait. Turn on notifications: "they can go and eat, and the phone buzzes when their lane is nearly ready, and again when it's their turn."
4. **Change my group.** One friend more? The group can change itself once, and keeps its place in line.

### 3. The counter (5 minutes, on the laptop)

1. **The lane board.** Every lane at a glance: in play with the time left, held for a called group, re-oiling, out of order, kept for tonight's league. Your phone's group is in the waitlist, marked "Online", with its deposit.
2. **Call and seat.** Press "Call next now" and watch the phone light up. Press "Seat" when they reach the counter. A called group that doesn't come in 5 minutes is skipped by itself.
3. **A walk-in without a phone.** Press "Add walk-in" and show the QR code they scan to follow the line.
4. **A lane's menu (⋯).** Extend a session, end it early, or close the lane for a re-oil or a repair.

### 4. Reservations (3 minutes)

1. **Requests.** Customers ask for a time from the website. The Requests page has a "good time to call now" tag and the number to tap. Press Confirm on one: the form is filled in, so pick the lanes and save.
2. **Reservations.** One day at a time, with a search across all days. Kavitha's reservation tomorrow is on lane 12, which is under repair: show "Move lanes".
3. **Block lanes.** Leagues, birthdays and school trips: block lanes for a time, every week if needed.

### 5. The owner's view (4 minutes)

1. **The dashboard.** "Needs attention" first: a late group, a reservation to move, requests to call back. Then switch to "30 days": sessions, people, no-shows, the average wait, the busiest hours by day of the week, and time lost to repairs. "This tells you when to put more staff on."
2. **Settings.**
    - Business name, colour and contact details.
    - Opening hours.
    - Booking rules: time, games or both, and how long one person takes per game.
    - Online waitlist: the last call before closing, and a longest wait.
    - Deposit.
3. **Activity log.** Everything anyone did, with who and when, and a check that nothing has been changed afterwards.

### Questions owners ask

| Question                             | Answer                                                                                                                                                                                                            |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Does it take real payments?          | Not yet. The deposit button is a stand-in; a provider such as FPX connects in one place. The deposit can also be switched off.                                                                                    |
| Does it send SMS?                    | No. Customers get a free notification on their phone, and a chime while their page is open. On iPhones only the chime works for now: notifications there need a web app manifest, which the app doesn't have yet. |
| Where does it run?                   | On a computer at the venue. Staff pages stay on the venue's own network; only the customer pages are reachable from the internet.                                                                                 |
| Can we sell games instead of hours?  | Yes: time, games, or both, set in one place.                                                                                                                                                                      |
| Does it handle prices and the bill?  | Not yet. It runs the line, the lanes and reservations.                                                                                                                                                            |
| What about customers' personal data? | Names and phone numbers only. Privacy steps for Malaysia's PDPA (a notice, a tick box and removing old data) are planned.                                                                                         |
| What if the internet goes down?      | The counter keeps working on the venue's network; only joining from home stops.                                                                                                                                   |

### Things to avoid in a demo

- Don't press **Pay** expecting a real payment page: it's the stand-in.
- Outside 9:00 AM to 3:00 AM the customer pages say the venue is closed. Present during opening hours, or reset just before.
- Other people may be using the demo at the same time, so the board can change under you. That's normal.
