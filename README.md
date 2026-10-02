# bowling_booking

A simple booking site for bowling businesses using Laravel and React. This is made because some bowling is too lazy to implement a online booking system relying needing to go there to join the waitlist and not knowing how long it is.

## Contents

- [What it does](#what-it-does)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Running the app](#running-the-app)
- [Staff users](#staff-users)
- [How the lanes work](#how-the-lanes-work)
- [Settings](#settings)
- [Project layout](#project-layout)
- [Tests and checks](#tests-and-checks)
- [Known gaps](#known-gaps)
- [Not built yet](#not-built-yet)
- [Versions](#versions)

## What it does

### For staff

| Screen | Address | What staff do there |
|---|---|---|
| Lane board | `/staff/board` | See every lane at a glance, run the walk-in waitlist, check in reservations due in the next 24 hours. Refreshes every 5 seconds. On the lane board, each lane has a "⋯" menu to extend a session, end it early, or close the lane. |
| Reservations | `/staff/reservations` | See one day's reservations, make, change and cancel them, and search every day by name or phone number. |
| Settings | `/settings` | Profile, password login and appearance. Admins also get Lanes and Users. |


### For customers

| Page | Address | State |
|---|---|---|
| Front page | `/` | The main page. Show the business name and two cards: waitlist and reservations. |
| Join the waitlist | `/waitlist/join` | **Sample only.** Shows the form and the "you are in line" screen, but saves nothing. |
| Reserve a lane | none | Placeholder card on the front page. |

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

   Set `APP_NAME` to the name of the business. It is shown on the front page, in the browser tab and in the staff sidebar.

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

This runs the scheduler, which `composer run dev` does **not** start. Without it, no-shows are never marked, finished sessions never complete, and the waitlist is never called automatically. It runs four jobs every minute:

| Command | What it does |
|---|---|
| `bookings:release-expired-holds` | Frees lanes held for a booking that was never confirmed. |
| `bookings:mark-no-shows` | Marks a reservation as a no-show once it is too late to check in, and frees its lanes. |
| `bookings:complete-finished` | Marks a session as completed when its time has run out. |
| `waitlist:process` | Skips parties who missed their call, then calls the next ones for free lanes. |

On a server, run the scheduler with a cron entry for `php artisan schedule:run` every minute.

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

- **Lanes**: set how many lanes the venue has.
- **Users**: add, edit and remove users, and reset their passwords.

Nobody can delete their own account. An admin removes other people's accounts from **Settings → Users**, so there is always at least one admin.

## How the lanes work

### Walk-ins

Staff add a party to the waitlist with a name, an optional phone number, a session length and up to 6 people. A bigger group is entered once per lane. "Call next" calls the parties at the front for the lanes that are free, and holds those lanes. "Seat" starts the session. A called party that is not seated within 5 minutes is skipped. This is the same as when a party join the waitlist through online but require a deposits.

### Reservations

- A group can reserve through online form or calling the business directly.
- Staff pick the date, a start time on the half hour (up to 90 days ahead), the length, the party size and one or more lanes. A phone number is required.
- Each reserved lane is closed to everyone for the 60 minutes before the start, so it is sure to be empty on time.
- Check-in opens 60 minutes before the start. Earlier than that it is refused.
- A reservation not checked in within 15 minutes of its start becomes a no-show and its lanes are freed.
- Staff can change or cancel a reservation until it is checked in.

### Sessions

- **Extend** adds time in 30-minute steps to every lane the party has. It is refused if any of those lanes is booked next.
- **End session** frees only the lane that was tapped. A party on several lanes keeps the others.

### Closing a lane

| Reason | Length | Ends |
|---|---|---|
| Re-oil, Maintenance | 15, 30, 45 or 60 minutes, starting when the lane is next free | By itself |
| Repair | Until reopened, with an optional estimate of 1 to 3 days | When staff reopen it |

A short closure is refused if it would run into a reservation. When a lane is closed for repair, reservations on it are flagged and cannot check in until staff move them to another lane.

### Times and money
The venue has no time zone or opening hours set yet. There are no prices yet either: every booking's total is 0.

## Settings

The rules above are set in `config/bowling.php`. Most can be overridden in `.env`:

| Setting | `.env` name | Default |
|---|---|---|
| Session step, also the shortest session | `BOWLING_SESSION_STEP_MINUTES` | 30 minutes |
| Longest session | `BOWLING_MAX_SESSION_MINUTES` | 240 minutes |
| People per lane, and per walk-in | `BOWLING_MAX_PLAYERS_PER_LANE` | 6 |
| How long a lane is closed before a reservation | `BOWLING_RESERVATION_LEAD_MINUTES` | 60 minutes |
| How early a reservation can be checked in | `BOWLING_CHECK_IN_OPENS_MINUTES` | 60 minutes |
| How far ahead a reservation can be made | `BOWLING_RESERVATION_MAX_DAYS_AHEAD` | 90 days |
| How long an unconfirmed booking keeps its lanes | `BOWLING_HOLD_MINUTES` | 10 minutes |
| How late a reservation can arrive before it is a no-show | `BOWLING_NO_SHOW_GRACE_MINUTES` | 15 minutes |
| How long a called walk-in has to check in | `BOWLING_WAITLIST_CHECKIN_MINUTES` | 5 minutes |

The closure lengths (15, 30, 45, 60 minutes), the repair estimates (1, 2, 3 days) and the version number are set in the file itself.

## Project layout

| Where | What is there |
|---|---|
| `app/Services` | The business rules. Controllers are thin and call these. |
| `app/Http/Controllers/Staff`, `.../Settings` | The staff screens and the settings pages. |
| `app/Http/Requests`, `app/Concerns` | Validation, with the rules shared between forms in `Concerns`. |
| `app/Models`, `app/Enums` | The tables and their fixed sets of values. |
| `app/Console/Commands` | `staff:create` and the four scheduled jobs. |
| `routes/web.php`, `staff.php`, `settings.php`, `console.php` | Public pages, staff pages, settings pages and the schedule. |
| `resources/js/pages` | One React page per screen. |
| `resources/js/components/staff` | The pieces of the staff screens: lane cards, dialogs, pickers. |
| `resources/js/types/staff.ts` | TypeScript types for the data the backend sends. |
| `tests/Feature` | The tests, grouped as `Staff`, `Services`, `Settings`, `Models`, `Console` and `Auth`. |

The main services:

| Service | What it does |
|---|---|
| `BookingService` | Walk-in bookings, reservations, change, cancel, check-in, extend, end a session, move lanes. |
| `LaneAvailability` | Which lanes are free in a time span. |
| `LaneClosures` | Short closures, repairs, adding time and reopening. |
| `WaitlistService` | Join, call next, seat, skip and leave. |
| `LaneBoard`, `ReservationSchedule` | Build what the lane board and the Reservations page show. |
| `BookingCleanup` | The scheduled clean-up of holds, no-shows and finished sessions. |
| `LaneInventory` | Sets the number of lanes. |

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
- The GitHub Actions workflow has not been set up for PostgreSQL yet.

## Not built yet

- Joining the waitlist online, with a private page for each party to watch its place in line.
- Booking a lane online.
- Prices, opening hours and a time zone for the venue.
- Live updates without polling.

## Versions

The version is set in `config/bowling.php` and shown in the staff sidebar. Each commit is named after the version it brings the app to.
