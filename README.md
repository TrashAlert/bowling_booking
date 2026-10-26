# bowling_booking

A simple booking site for bowling businesses using Laravel and React. This is made because some bowling is too lazy to implement a online booking system relying needing to go there to join the waitlist and not knowing how long it is.

## Contents

- [What it does](#what-it-does)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Running the app](#running-the-app)
- [Going live: staff inside the venue, customers from the internet](#going-live-staff-inside-the-venue-customers-from-the-internet)
- [Staff users](#staff-users)
- [How the lanes work](#how-the-lanes-work)
- [Settings](#settings)
- [Connecting a real payment provider](#connecting-a-real-payment-provider)
- [Project layout](#project-layout)
- [Tests and checks](#tests-and-checks)
- [Known gaps](#known-gaps)
- [Not built yet](#not-built-yet)
- [Versions](#versions)

## What it does

### For staff

| Screen       | Address               | What staff do there                                                                                                                                                                                                                |
| ------------ | --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard    | `/dashboard`          | Where staff land after logging in: what the lanes and the line are doing right now, what needs attention, and how the venue has been doing over a chosen span of days.                                                             |
| Lane board   | `/staff/board`        | See every lane at a glance, run the walk-in waitlist, check in reservations due in the next 24 hours. Refreshes every 5 seconds. On the lane board, each lane has a "⋯" menu to extend a session, end it early, or close the lane. |
| Reservations | `/staff/reservations` | See one day's reservations, make, change and cancel them, and search every day by name or phone number. Block lanes for a league night or an event.                                                                                |
| Requests     | `/staff/requests`     | Reservation requests customers sent from `/reserve`, to call back and confirm or decline.                                                                                                                                          |
| Settings     | `/settings`           | Profile, password and appearance. Admins also get the venue, booking and staff settings (see [Staff users](#staff-users)).                                                                                                         |

### For customers

| Page              | Address          | State                                                                                                                                                      |
| ----------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front page        | `/`              | The main page. Show the business name and two cards: waitlist and reservations.                                                                            |
| Join the waitlist | `/waitlist/join` | Working, with a **stand-in payment**. A party fills in the form, pays a deposit, and gets a private page showing its place in line. No money is taken yet. |
| Reserve a lane    | `/reserve`       | Working. The customer sends a request with a time they can be called; staff phone them and confirm it from **Requests**. Nothing is booked until then.     |

## Tech stack

- **Backend:** PHP 8.3 or newer (developed on 8.5), Laravel 13, Fortify for login
- **Frontend:** Inertia 3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Wayfinder for typed routes
- **Database:** PostgreSQL (developed on 18)
- **Tests:** Pest

PostgreSQL is required. The rule that two bookings can never overlap on one lane is a PostgreSQL exclusion constraint, so SQLite and MySQL cannot run the app.

## Getting started

You need PHP, Composer, Node.js and a running PostgreSQL server.

1. **Install the dependencies.**

    ```bash
    composer install
    npm install
    ```

2. **Create your `.env` file and app key.**

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

3. **Point `.env` at PostgreSQL.** `.env.example` still defaults to SQLite, which will not work. Change the database lines to:

    ```ini
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=bowling_booking
    DB_USERNAME=your_postgres_user
    DB_PASSWORD=your_postgres_password
    ```

    Set `APP_NAME` to the name of the business. It is shown on the front page, in the browser tab and in the staff sidebar until an admin saves a name under **Settings → Business**, which then takes its place.

4. **Create the databases.** The second one is for the tests.

    ```bash
    createdb bowling_booking
    createdb bowling_test
    ```

5. **Create the tables.**

    ```bash
    php artisan migrate
    ```

6. **Create the first admin.** You are asked for a password.

    ```bash
    php artisan staff:create "Admin" admin@example.com --admin
    ```

7. **Build the frontend once.** The tests need this too.

    ```bash
    npm run build
    ```

8. **Add the lanes.** Log in as the admin and set the number of lanes under **Settings → Lanes**. A new database has none.

## Running the app

The app needs two things running during development:

```bash
composer run dev
```

This starts the web server, the queue worker, the log viewer and the Vite dev server. The app is then at http://localhost:8000.

```bash
php artisan schedule:work
```

This runs the scheduler, which `composer run dev` does **not** start. Without it, no-shows are never marked, finished sessions never complete, and the waitlist is never called automatically. It runs four jobs every minute, and one each night:

| Command                          | What it does                                                                                                               |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `bookings:release-expired-holds` | Frees the lane held for a called walk-in that did not check in in time.                                                    |
| `bookings:mark-no-shows`         | Marks a reservation as a no-show once it is too late to check in, and frees its lanes.                                     |
| `bookings:complete-finished`     | Marks a session as completed when its time has run out.                                                                    |
| `waitlist:process`               | Skips parties who missed their call, calls the next ones for free lanes, and tells those nearly at the front to get ready. |
| `activity-log:prune`             | At 4:00 AM, removes activity log lines older than 18 months.                                                               |

On a server, run the scheduler with a cron entry for `php artisan schedule:run` every minute.

The dashboard's **Needs attention** warns staff when the scheduler has stopped:

- **What it watches:** each of the four every-minute jobs notes when it last finished, in the app's cache. A job that hasn't finished for 5 minutes is reported.
- **One job stuck on its own:** it is named. A job that crashed stays locked for up to a day; `php artisan schedule:clear-cache` frees it.
- **Emptying the cache** (`php artisan cache:clear`) clears these notes, so the warning shows until the jobs next run, a minute later.
- **In development** the warning shows until `php artisan schedule:work` is running.

## Going live: staff inside the venue, customers from the internet

The app is meant to run on a computer inside the venue. Staff use it on the venue's own network. Customers reach only the front page, the waitlist and the reservation page from the internet. Staff logins have no two-factor codes and no password reset by email, so the staff pages must **not** be reachable from outside.

```text
customer's phone ──internet──> tunnel ──> public gate (port 8088) ──> app
staff computer ───venue network─────────────────────────────────────> app
```

### 1. The public gate

Put a small nginx server in front of the app that lets through the customer pages and nothing else. Point the tunnel (or the router's port forward) at this port, never at the app itself.

```nginx
server {
    listen 127.0.0.1:8088;

    # Don't announce nginx's version.
    server_tokens off;

    # Where the app itself is served on the venue's network.
    set $app http://127.0.0.1:8000;

    # Tell the app who the customer is and that they are on HTTPS.
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;

    # The customer pages.
    location = /          { proxy_pass $app; }
    location = /reserve   { proxy_pass $app; }
    location = /reserve/thanks { proxy_pass $app; }
    location ^~ /waitlist/ { proxy_pass $app; }
    # The business logo and the front page's cover photo, set under Settings.
    location = /branding/logo { proxy_pass $app; }
    location = /branding/cover { proxy_pass $app; }

    # The files those pages need.
    location ^~ /build/   { proxy_pass $app; }
    location ~ ^/(sw\.js|favicon\.ico|favicon\.svg|apple-touch-icon\.png|robots\.txt)$ { proxy_pass $app; }

    # Everything else, including /login, /staff and /settings.
    location / { return 404; }
}
```

When a new customer page is added, add its address here too.

The app sends its own security headers on every response, so the gate needs none:

- **Framing:** other sites may not show its pages in a frame.
- **File types:** browsers may not read a file as another type than it says it is.
- **Addresses:** page addresses are not sent to other sites. A ticket page's address is the customer's secret link.
- **Scripts:** only the app's own scripts may run. Its inline scripts carry a code that changes with every response.

It also removes PHP's `X-Powered-By` header. The one exception is Laravel's own debug page, which gets no script rule, and only shows while `APP_DEBUG` is on.

Cookies set over HTTPS, as customers' are through the gate, are marked HTTPS-only by themselves. Leave `SESSION_SECURE_COOKIE` unset: setting it to `true` marks every cookie HTTPS-only, and staff on the venue's plain-HTTP network could then no longer log in.

### 2. Settings in `.env`

```ini
APP_ENV=production
APP_DEBUG=false
# The gate runs on the same computer as the app.
TRUSTED_PROXIES=127.0.0.1
# The public HTTPS address customers reach the app at.
BOWLING_PUBLIC_URL=https://bowl.example.com
```

`TRUSTED_PROXIES` matters: without it every customer looks like the gate itself, so they all share one limit on joining the waitlist, and links are made for HTTP while the customer is on HTTPS.

`BOWLING_PUBLIC_URL` matters too: the QR code a walk-in scans at the counter links there. Without it the code holds the address the counter's screen was opened on, which a phone on mobile data can't reach.

Even with `TRUSTED_PROXIES` set, the limit on sending the public forms is counted per internet address, and joining, reserving and turning on notifications share it: 5 a minute. Customers on the venue's guest Wi-Fi, or on a mobile network that shares one address among many phones, share that limit. The sixth gets a "Too many tries" page.

### 3. Checks before opening it up

- From a phone on mobile data, the front page and `/waitlist/join` load, and `/login` and `/staff/board` say "not found".
- From a staff computer on the venue's network, `/login` works.
- The public address is HTTPS. Push notifications need it.
- `npm run build` has been run, and there is no `public/hot` file (it is left behind by `npm run dev`).
- The scheduler is running (see [Running the app](#running-the-app)). Nothing is sent to the queue, so a queue worker is not needed.
- A business name has been saved under **Settings → Business**, so "Laravel" shows nowhere.
- `APP_DEBUG` is `false`. While it is on, a fault shows Laravel's own page with its details instead of the venue's error page.
- Dependencies are installed with `composer install --no-dev`, so the tests cannot be run on this computer.

## Staff users

Public registration is turned off. Staff accounts are created from the command line on the server:

```bash
php artisan staff:create "Counter" Counter@example.com
```

You are asked for the password. Add `--admin` to create an admin instead of counter staff:

```bash
php artisan staff:create "Admin" admin@example.com --admin
```

For scripted setups, pass the password with `--password=...` instead of typing it.

Only users with the `staff` or `admin` role can open pages under `/staff`. Admins can do everything staff can.

Once there is an admin, the rest can be done in the app. Under **Settings**, admins also see:

- **Business**: the name and logo shown on every page, the brand colour of the customer pages, the contact details and social media links at the bottom of the customer pages (each optional), and the currency symbol and time zone. Until a name is saved, `APP_NAME` from `.env` is used.
- **Front page**: the words on the front page, a cover photo, whether the opening hours are listed, and an announcement banner across the top of every customer page.
- **Lanes**: set how many lanes the venue has.
- **Opening hours**: when the venue is open, day by day. Customers can only join the waitlist online, or pick a reservation time, while it is open. Until hours are saved the venue counts as always open. Staff are not limited by them.
- **Booking rules**: the rules the waitlist and reservations follow, such as how long a called party has to check in.
- **Online waitlist**: close joining the waitlist online, with a message for customers. Groups already in line stay in it, and staff can still add walk-ins. Also the last call before closing, and an optional longest wait for joining online.
- **Deposit**: whether customers pay a deposit to join online when they would have to wait, and how much.
- **Users**: add, edit and remove users, and reset their passwords.
- **Dashboard**: which parts of the dashboard everyone sees.
- **Activity log**: everything staff, customers and the app itself did (see [The activity log](#the-activity-log)).

Nobody can delete their own account. An admin removes other people's accounts from **Settings → Users**, so there is always at least one admin.

## How the lanes work

### Walk-ins

Staff add a party to the waitlist with a name, an optional phone number (at least 10 digits when given), a session length and up to 6 people. A party that has to wait is shown a QR code to scan, which opens its own page about its place in line. A bigger group is entered once per lane. "Call next" calls the parties at the front for the lanes that are free, and holds those lanes. "Seat" starts the session. A called party that is not seated within 5 minutes is skipped. This is the same as when a party join the waitlist through online but require a deposits.

### Joining the waitlist online

- A customer fills in the form at `/waitlist/join`: name, phone (digits only, at least 10), up to 6 people and a session length.
- One phone can have two groups in the line at once (a setting), and can send the form five times a minute. A bigger group asks at the counter.
- **Last call:** a group can only join online if its session, starting after the estimated wait, ends by closing time. Late in the evening only shorter sessions can join; the form says so and the button is disabled. An admin can turn this off under **Settings → Online waitlist**. Groups already in the line are not affected and are still called after closing.
- **Longest wait:** an admin can also set a wait above which groups are asked to come to the counter instead of joining online (no limit by default). Walk-ins added by staff are never limited.
- Both are checked again when a group comes back to pay its deposit.
- If a lane is free for them right now, there is no deposit and they are put in line at once. A lane that others are already waiting for does not count as free.
- Otherwise they pay a deposit (RM 10.00 by default). They are only put in line once it is paid, at the end of the line as it stands at that moment.
- They get a private page at `/waitlist/{token}` that refreshes every 5 seconds: their place in line, then their lane and a countdown when called, then when their session ends.
- That page, and the deposit page, ask search engines not to list them. A day after the group's visit ends (it left, missed its call, or finished playing), the page shows "This page has expired" with nothing about the group. An unpaid deposit page expires a day after it was started.
- A group is called when a lane is free for its whole session, so a group behind it that wants a shorter session can be called first. The page then says so, and the group keeps its place.
- The phone remembers each place-in-line page it has opened, so a customer who closed it is led back from the front page with a "View your place" button. This only works in the same browser: a page opened inside Instagram's or WhatsApp's own browser isn't known to Chrome or Safari.
- The deposit comes off their bill if they play. It is returned if they leave before being called, and kept if they are called and do not check in within 5 minutes.
- The form and the private page show an estimated wait. It plays the line forward using what is booked on each lane, so it is only as good as sessions ending on time.
- Staff see the party in the lane board's waitlist like any other, marked "Online" with the deposit paid.
- The payment is a stand-in: the "Pay" button takes no money. A real provider (FPX, DuitNow, cards) plugs in without changing the rest; see [Connecting a real payment provider](#connecting-a-real-payment-provider).

### Being told when called

A waiting party can turn on two alerts from its own page:

- **A notification** (web push), which reaches the phone even when it is locked or the page is closed. It works in Chrome and Firefox on Android and on desktop. It does not work on an iPhone yet: iPhones only allow it for a site installed on the Home Screen as a web app, which needs a web app manifest, and the app has none. iPhone users only see the chime.
- **A chime**, which sounds only while the page is open with the screen on. The phone also vibrates where it can.

Notifications need a key pair, made once:

```bash
php artisan push:keys
```

Copy the two lines it prints into `.env`. Without them the notification option is hidden and nothing is sent. On a live site they also need HTTPS, and `VAPID_SUBJECT` should be set to a `mailto:` address a push service can reach you at. Making new keys later turns notifications off for phones that turned them on with the old ones.

The notification is sent when the party is called, whether by the scheduler or by staff pressing "Call next now". The script that shows it on the phone is `public/sw.js`.

A party with notifications on also gets a "get ready" notification once its estimated wait is 10 minutes or less (`BOWLING_GET_READY_MINUTES`), so it can be back at the counter by the time it is called:

- It is sent once per party, by the scheduler, so it needs the scheduler running.
- It is only a heads-up from the estimate. The time to check in still starts when the party is called.
- A party whose wait grows again afterwards is not told a second time.
- Each one is written to the activity log.

### Reservation requests from customers

- A customer fills in `/reserve`: name, phone, date and start time (only times inside opening hours), length, party size, **when we can call them**, and a note. Nothing is booked and no lanes are held.
- Staff see it under **Requests** in the sidebar, with a count of those waiting. Each shows the phone number to tap and call, and a "good time to call now" tag while the time the customer gave has come.
- **Confirm** opens the usual reservation form filled in from the request. Staff pick the lanes, change anything agreed on the call, and save. **Decline** takes an optional reason, kept for staff.
- A request nobody dealt with before its time moves to the "dealt with or missed" list by itself.

### Reservations

- A group can reserve through online form or calling the business directly.
- Staff pick the date, a start time on the half hour (up to 90 days ahead), the length, the party size and one or more lanes. A phone number is required: digits only, at least 10.
- Each reserved lane is closed to everyone for the 60 minutes before the start, so it is sure to be empty on time.
- Check-in opens 60 minutes before the start. Earlier than that it is refused.
- A reservation not checked in within 15 minutes of its start becomes a no-show and its lanes are freed.
- Staff can change or cancel a reservation until it is checked in.

### Games instead of time

Some venues sell games rather than time. Under **Settings → Booking rules**, **What groups book** is time only (the default), games only, or time or games, where the group picks. Once games are on, walk-ins, the online waitlist, staff reservations and the reservation request form all offer them.

- **The lane is still kept for a time:** the games × the people on the lane × the minutes one person takes per game (10 by default), rounded up to the next half hour. For example, 4 people bowling 3 games keep a lane for 2 hours. A group bigger than one lane is spread over its lanes, which bowl side by side.
- **Everything else follows from that time:** the wait estimate, calling the next group, the hour kept clear before a reservation, the opening hours check and the activity log.
- **Staff end the session** from the lane's menu when the games are done early, or **extend** it if the group is slow, as for any session.
- **Up to 10 games** per group by default.
- **Shown everywhere a session is:** "3 games · about 2 h" on the lane board, the waitlist, the Reservations and Requests pages, and the customer's own pages.

### Blocking lanes for leagues and events

On the **Reservations** page, **Block lanes** keeps chosen lanes for something other than a booking, such as a league night, a private event or a school trip:

- **What it asks:** what the lanes are for, the date, from and until when (a block can run past midnight, up to a day), the lanes, and how many weeks in a row.
- **Repeating:** each further week is the same day of the week and the same clock time. If a lane is taken in any of the weeks, nothing is blocked, and the message names the lane and the day.
- **Kept clear beforehand:** like a reservation, each lane closes for the lead time in Booking rules (60 minutes by default) before the start.
- **While blocked:** nobody in the waitlist is called to the lane, and nothing else can be booked on it. The lane board shows it as "Blocked" with what it is for.
- **Removing:** each week is removed on its own from that day's list. A block under way ends at once, and its lanes are free again.
- **The activity log** has a line for each block made or removed.

### Sessions

- **Extend** adds time in 30-minute steps to every lane the party has. It is refused if any of those lanes is booked next.
- **End session** frees only the lane that was tapped. A party on several lanes keeps the others.

### Closing a lane

| Reason              | Length                                                        | Ends                 |
| ------------------- | ------------------------------------------------------------- | -------------------- |
| Re-oil, Maintenance | 15, 30, 45 or 60 minutes, starting when the lane is next free | By itself            |
| Repair              | Until reopened, with an optional estimate of 1 to 3 days      | When staff reopen it |

A short closure is refused if it would run into a reservation, including the time a lane is kept clear before one. When a lane is closed for repair, reservations on it are flagged and cannot check in until staff move them to another lane. A group that checked in early is flagged too, and can be moved until its time begins. A party already playing on the lane finishes its session there.

### Times and money

The venue's time zone is set under **Settings → Business** (Asia/Kuala_Lumpur until changed), and opening hours and the dashboard's days are read in it. The Reservations page still reads its days in the time zone of the device it is opened on, which is the same while staff devices are set to the venue's zone.

There are no prices yet: every booking's total is 0. The only money handled is the online waitlist deposit.

### The activity log

Every action is written to the activity log, which admins read under **Settings → Activity log**, newest first, filtered by days, person, kind or words. That covers:

- **Staff:** the waitlist, reservations and their requests, sessions and lane closures.
- **Admins:** every settings change, with the old and new value, and user accounts added, changed or removed.
- **Customers:** joining online, deposits, leaving the line, notifications and reservation requests.
- **The scheduler:** calls, missed calls, no-shows and finished sessions.
- **Logins:** logging in, failed attempts and logging out.

- **Each line holds:** who did it, when, what, and from which internet address.
- **Written with the action:** the line is written in the same database transaction as what it records, so neither can exist without the other.
- **Nobody can change it:** the database refuses any change to a line, removing one, or emptying the table, even from inside the app's own code.
- **Kept for 18 months:** a scheduled job removes older lines, the only removal the database allows.
- **Tamper check:** each line carries a fingerprint of itself and the line before it, signed with `APP_KEY`. **Check nothing has been changed** on the page, or `php artisan activity-log:verify`, finds a line edited directly in the database.
- **Keep `APP_KEY` safe:** changing it makes every older line fail the check.
- **Never written:** phone numbers and passwords. A changed phone number or a reset password is noted as changed, without the value.

### When something goes wrong

A mistyped link, a page left open too long, too many tries in a row, maintenance or a fault show a page of the venue's own site, with its name and colours and a way back, instead of Laravel's bare error page. While `APP_DEBUG` is on, a fault still shows Laravel's own page with the details, for development.

## Settings

The rules above are set in `config/bowling.php`. Most can be overridden in `.env`:

| Setting                                                  | `.env` name                          | Default           |
| -------------------------------------------------------- | ------------------------------------ | ----------------- |
| The venue's time zone, which opening hours are read in   | `BOWLING_TIMEZONE`                   | Asia/Kuala_Lumpur |
| Session step, also the shortest session                  | `BOWLING_SESSION_STEP_MINUTES`       | 30 minutes        |
| Longest session                                          | `BOWLING_MAX_SESSION_MINUTES`        | 240 minutes       |
| Whether groups book time, games or either                | `BOWLING_SESSION_MODE`               | time              |
| Minutes one person takes to bowl one game                | `BOWLING_MINUTES_PER_PLAYER_GAME`    | 10 minutes        |
| Most games one group can book                            | `BOWLING_MAX_GAMES`                  | 10                |
| People per lane, and per walk-in                         | `BOWLING_MAX_PLAYERS_PER_LANE`       | 6                 |
| How long a lane is closed before a reservation           | `BOWLING_RESERVATION_LEAD_MINUTES`   | 60 minutes        |
| How early a reservation can be checked in                | `BOWLING_CHECK_IN_OPENS_MINUTES`     | 60 minutes        |
| How far ahead a reservation can be made                  | `BOWLING_RESERVATION_MAX_DAYS_AHEAD` | 90 days           |
| How late a reservation can arrive before it is a no-show | `BOWLING_NO_SHOW_GRACE_MINUTES`      | 15 minutes        |
| How long a called walk-in has to check in                | `BOWLING_WAITLIST_CHECKIN_MINUTES`   | 5 minutes         |
| Wait at which a party is told to get ready               | `BOWLING_GET_READY_MINUTES`          | 10 minutes        |
| Deposit to join the waitlist online, in cents            | `BOWLING_WAITLIST_DEPOSIT_CENTS`     | 1000 (RM 10.00)   |
| Who takes that deposit                                   | `BOWLING_DEPOSIT_PAYMENT_PROVIDER`   | stand_in          |
| Symbol shown in front of amounts of money                | `BOWLING_CURRENCY_SYMBOL`            | RM                |
| The public address the walk-in QR code links to          | `BOWLING_PUBLIC_URL`                 | (empty)           |

`config/bowling.php` also has `hold_minutes` (`BOWLING_HOLD_MINUTES`), left from an online booking flow that was never built. Nothing uses it: a called walk-in's lane is held for the check-in time in the table above.

The closure lengths (15, 30, 45, 60 minutes), the repair estimates (1, 2, 3 days) and the version number are set in the file itself.

Some rules can also be changed by an admin under **Settings**, with no need to edit `.env`. Once saved there, the saved value is used and the `.env` value is ignored:

| Rule                                                      | Where in Settings |
| --------------------------------------------------------- | ----------------- |
| Deposit to join the waitlist online                       | Deposit           |
| How long a called party has to check in                   | Booking rules     |
| How many groups one phone can have in the online waitlist | Booking rules     |
| How late a reservation can arrive before it is a no-show  | Booking rules     |
| How long a lane is closed before a reservation            | Booking rules     |
| How early a reservation can be checked in                 | Booking rules     |
| How far ahead a reservation can be made                   | Booking rules     |
| People per lane, and per walk-in                          | Booking rules     |
| Longest session                                           | Booking rules     |
| Whether groups book time, games or either                 | Booking rules     |
| Minutes one person takes to bowl one game                 | Booking rules     |
| Most games one group can book                             | Booking rules     |
| Symbol shown in front of amounts of money                 | Business          |
| The venue's time zone                                     | Business          |

## Connecting a real payment provider

The deposit for joining the waitlist online is taken by a "payment provider". The only one that ships is the stand-in, which takes no money: pressing "Pay" counts as paid. A real provider is added as one class and two lines of settings. Nothing else in the app has to change.

### How a payment goes

1. The party presses "Pay". The app asks the provider to start taking the deposit.
2. The provider answers one of two ways:
    - **Paid on the spot.** The party is put in line at once. This is what the stand-in does.
    - **Pay on my page.** The provider gives the address of its payment page and its own reference for the payment. The app saves the reference on the deposit and sends the customer to that page.
3. The customer pays and is sent back to the pay page, `/waitlist/deposit/{token}`. That page waits and moves on by itself once the payment is known.
4. The provider's server posts a notice to `/waitlist/deposit/notice/{name}`. The provider's class checks the notice and says which payment it proves was paid. The app puts that party in line, once, however many times the notice arrives.

### 1. Write the provider class

Put it next to the stand-in, in `app/Services/Payments`, and implement `DepositPaymentProvider`. The three methods are all there is:

```php
<?php

namespace App\Services\Payments;

use App\Models\WaitlistDeposit;
use Illuminate\Http\Request;

class ExampleBankPaymentProvider implements DepositPaymentProvider
{
    // Saved with each deposit, and the last part of the notice address.
    public function name(): string
    {
        return 'example_bank';
    }

    public function begin(WaitlistDeposit $deposit, string $returnUrl, string $notifyUrl): PaymentStart
    {
        // Ask the provider to create a payment of $deposit->amount_cents,
        // telling it to send the customer back to $returnUrl and to post
        // its notice to $notifyUrl. Then hand back its payment page and
        // its reference for the payment.

        return PaymentStart::redirectTo($paymentPageUrl, $providerReference);
    }

    public function paidReference(Request $request): ?string
    {
        // Check the notice is really from the provider (its signature),
        // and that it says "paid". If so return the same reference that
        // begin() handed back. Return null for anything else.

        return $reference;
    }
}
```

Rules the class must keep:

- **Check every notice.** The notice address is open to the internet, so `paidReference()` must verify the provider's signature and return `null` when it does not check out, or when the payment failed or is still pending.
- **Do not put the party in line yourself.** Only return the reference. The app finds the deposit and puts the party in line.
- **`begin()` can run more than once for one deposit**, when a customer comes back unpaid and presses "Pay" again. Only the latest reference is kept, so reuse the earlier payment where the provider allows it.
- **Money is in cents.** RM 10.00 is `1000`.

Keep the provider's keys in `.env`, read through `config/services.php`, as the push notification keys are.

### 2. Register it

Add the class to the list in `config/bowling.php`, under the same name its `name()` returns:

```php
'deposit_payments' => [
    'provider' => env('BOWLING_DEPOSIT_PAYMENT_PROVIDER', StandInPaymentProvider::NAME),

    'providers' => [
        StandInPaymentProvider::NAME => StandInPaymentProvider::class,
        'example_bank' => ExampleBankPaymentProvider::class,
    ],
],
```

### 3. Switch it on

```ini
BOWLING_DEPOSIT_PAYMENT_PROVIDER=example_bank
```

From then on the "no money is taken" warning on the pay page is gone and "Pay" goes to the provider. To go back, set it to `stand_in`. A name that is not in the list stops the app with a message saying so.

### 4. Give the provider the notice address

Most providers take it with each payment, which `begin()` already receives as `$notifyUrl`. If yours wants it typed into its dashboard instead, it is:

```text
https://your-public-address/waitlist/deposit/notice/example_bank
```

What the address answers:

| Answer | Meaning                                                                   |
| ------ | ------------------------------------------------------------------------- |
| 204    | The payment is known and the party is in line. Safe to receive again.     |
| 400    | The notice proved nothing: wrong signature, or not a completed payment.   |
| 404    | The reference is not one of ours, or the name is not the provider in use. |
| 429    | More than 60 notices in a minute from one address.                        |

### Before taking real money

- **The site must be on HTTPS at its public address**, with `TRUSTED_PROXIES` set as in [Going live](#going-live-staff-inside-the-venue-customers-from-the-internet). The return and notice addresses handed to the provider are built from the address the customer is on, so without it they come out as `http://` or as the venue's inside address.
- **The public gate already lets the notice through**, since it sits under `/waitlist/`.
- **Try the whole path with the provider's test mode.** `tests/Feature/WaitlistDepositPaymentTest.php` has a pretend bank that shows how to test a provider: a redirect, a good notice, a forged one, a failed one and a repeat.
- **A party that has paid is put in line even if the venue has closed since it pressed "Pay"**, because its money has been taken.
- **Refunds are not part of this.** A deposit that is to be returned is still returned by hand at the counter, and there is no list of them for staff yet.
- **No deposit, no provider.** A party that joins while a lane is free, or while the deposit is turned off under Settings, never reaches the provider.

## Project layout

| Where                                                        | What is there                                                                          |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| `app/Services`                                               | The business rules. Controllers are thin and call these.                               |
| `app/Http/Controllers/Staff`, `.../Settings`                 | The staff screens and the settings pages.                                              |
| `app/Http/Requests`, `app/Concerns`                          | Validation, with the rules shared between forms in `Concerns`.                         |
| `app/Models`, `app/Enums`                                    | The tables and their fixed sets of values.                                             |
| `app/Console/Commands`                                       | `staff:create` and the four scheduled jobs.                                            |
| `routes/web.php`, `staff.php`, `settings.php`, `console.php` | Public pages, staff pages, settings pages and the schedule.                            |
| `resources/js/pages`                                         | One React page per screen.                                                             |
| `resources/js/components/staff`                              | The pieces of the staff screens: lane cards, dialogs, pickers.                         |
| `resources/js/types/staff.ts`                                | TypeScript types for the data the backend sends.                                       |
| `tests/Feature`                                              | The tests, grouped as `Staff`, `Services`, `Settings`, `Models`, `Console` and `Auth`. |

The main services:

| Service                            | What it does                                                                                 |
| ---------------------------------- | -------------------------------------------------------------------------------------------- |
| `BookingService`                   | Walk-in bookings, reservations, change, cancel, check-in, extend, end a session, move lanes. |
| `LaneAvailability`                 | Which lanes are free in a time span.                                                         |
| `LaneClosures`                     | Short closures, repairs, adding time and reopening.                                          |
| `WaitlistService`                  | Join, call next, seat, skip and leave.                                                       |
| `WaitlistDeposits`                 | Joining the waitlist online: the deposit, and putting the party in line once it is paid.     |
| `Payments\DepositPaymentProvider`  | What a payment provider must do to take that deposit. `StandInPaymentProvider` takes none.   |
| `WaitlistTicket`                   | Builds what a party sees on its own waitlist page.                                           |
| `LaneBoard`, `ReservationSchedule` | Build what the lane board and the Reservations page show.                                    |
| `BookingCleanup`                   | The scheduled clean-up of holds, no-shows and finished sessions.                             |
| `LaneInventory`                    | Sets the number of lanes.                                                                    |
| `StaffDashboard`                   | Everything the dashboard shows: right now, needs attention, and the numbers over time.       |
| `Branding`, `FrontPage`            | What customers see of the business: name, logo, colour, wording, photo and announcement.     |
| `WaitlistPlaces`                   | Remembers which place-in-line pages a phone has opened, for the way back on the front page.  |

Every span of time a lane is taken is one row in `lane_allocations`: a session, the closed hour before a reservation, or a closure. The database constraint `lane_allocations_no_overlap` refuses any two live rows that overlap on the same lane. That constraint is what enforces every "the lane is taken" rule, so new code should rely on it and not work around it.

## Tests and checks

```bash
php artisan test --compact                          # everything
php artisan test --compact tests/Feature/Staff      # one folder or file
php artisan test --compact --filter="check in"      # tests matching a name
```

The tests use the `bowling_test` database (set in `phpunit.xml`) and need `npm run build` to have been run.

Frontend checks:

```bash
npm run check          # formatting and lint
npm run check:fix      # fix what can be fixed
npm run types:check    # TypeScript
npm run build
```

PHP formatting, for the files you changed:

```bash
vendor/bin/pint --dirty
```

`composer test` and `composer ci:check` do not pass at the moment. They also run Pint and PHPStan over the whole project, and two older files (`BookingService.php` and `WaitlistService.php`) have formatting differences and the older code has PHPStan errors that have been left for later.

## Known gaps

- An extension cannot be shortened or undone.
- A party that is already playing cannot be moved to another lane.
- A lane closed for repair cannot be picked for a new reservation, even for a date after its estimate.
- A closure cannot be scheduled for a chosen time. It starts now, or when the current session ends.
- A check-in cannot be undone, and a no-show cannot be brought back.
- Every reservation creates a new customer record. Returning customers are not matched.
- `.env.example` still defaults to SQLite.
- The GitHub Actions workflow has a PostgreSQL service but has never run, and its `composer ci:check` step would fail for the reasons under [Tests and checks](#tests-and-checks).
- Reservations confirmed from the online request form are saved as phone bookings, so the dashboard can't tell the two apart.
- Late-night sessions are counted on their calendar day: a session at 12:30 am on a Friday night counts as Saturday.
- The `has_bumpers` lane flag appears on the lane board, but nothing in the app can set it.
- A lane block can't be changed; remove it and block the lanes again. A block isn't flagged when one of its lanes goes out of order.

## Not built yet

- A real payment provider for the waitlist deposit. The place for one is ready; see [Connecting a real payment provider](#connecting-a-real-payment-provider).
- A list for staff of deposits to return, and refunds through the provider.
- Booking a lane online.
- Prices.
- Push notifications on iPhone, which need a web app manifest.
- Holiday and one-off opening hours: only a weekly pattern can be set.
- Live updates without polling.

## Versions

The version is set in `config/bowling.php` and shown in the staff sidebar. Each commit is named after the version it brings the app to.
