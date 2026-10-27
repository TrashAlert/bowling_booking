# Handoff: bowling lane booking system, v1.5.0 to v1.8.0

Written on 2026-10-26 for whoever picks the project up next, with no memory of the work so far. This session began with a full review of the project, then fixed what the owner chose from it (v1.6), added a strict activity log (v1.7), and finished with a security audit and some of its fixes (v1.8, not committed yet). The README covers setup and the day-to-day rules; this file is about what changed, why, and what to watch out for. The handoff for v1.0 to v1.5 is no longer in the project root; what still matters from it is carried over here.

## Where things stand

- **Committed by the owner:** `b9b0fb6 v1.6` (2026-10-16) and `7579180 v1.7` (2026-10-19). `bowling.version` is `1.8.0`, which the owner named for the security fixes. The owner commits and names every version.
- **Not committed:** v1.8, the security fixes (listed under [v1.8](#v18-security-audit-and-fixes-not-committed)), the README edits made since v1.6, and the deleted `app/Actions/Fortify/ResetUserPassword.php`, which has been left uncommitted since before v0.8.0.
- **README:** it was committed with v1.6, so the old rule of keeping it uncommitted no longer holds. Since then it has gained the activity log section, the security headers and cookie notes, the page-expiry line, and `server_tokens off;` in the nginx gate.
- **Tests:** the last full run had 871 tests: 858 passed and 13 skipped (`php artisan test --compact`). The 13 are tests for features the owner switched off (two-factor, password reset, registration).
- **Frontend:** `npm run check`, `npm run types:check` and `npm run build` pass.
- **`composer test` still does not pass:**
    - **Pint:** only `BookingService.php` and `WaitlistService.php` are flagged. The owner said to leave both alone, and this session only added log lines to them.
    - **PHPStan:** 306 errors, up from 267 at 1.5. Most are the same noise: models declare no property types. One is in new code: the `when()` closure in `Settings\UserController::update()`.
    - **Memory:** PHPStan needs `--memory-limit=1G`.

## Read this first: the databases

The rules made after the dev database was wiped on 2026-10-02 still stand.

- `tests/TestCase.php` refuses to run unless the database is `bowling_test`. Do not remove it.
- Never export the app's `DB_*` variables in a shell that runs tests. For a one-off, put the variable in front of a single command.
- Before migrating the dev database: `pg_dump` it, rehearse on a copy, migrate, compare row counts.
- **Still open:** the guard checks the configured database name, not the real connection, so an exported `DB_URL` would get past it.
- The PostgreSQL server on the owner's Mac runs in `Asia/Kuala_Lumpur`; the app's connection is UTC. In a plain `psql` session, run `set timezone = 'UTC';` before reading or writing times by hand.
- Running the tests empties `bowling_test`.

**One migration this session**, run on the dev database:

| Migration                                      | What it adds                                                  |
| ---------------------------------------------- | ------------------------------------------------------------- |
| `2026_10_16_083136_create_activity_logs_table` | `activity_logs`, plus database rules that make it append-only |

- **How it was run:** on 2026-10-16, after a dump and a rehearsal on a copy. On the copy it refused an edit, rolled back cleanly and ran again. Afterwards the only differences were the new empty table and one more row in `migrations`.
- **Backups kept:** two databases are kept until the owner says to drop them. Ask first.
    - `bowling_rehearsal_activity` holds the dev data from before this migration.
    - `bowling_rehearsal`, from v1.2, holds it from before the repair log.
- The dump file was in the session scratchpad and is gone.

**`activity_logs` is append-only:**

- **What the database refuses:** a trigger rejects any `UPDATE`, any `DELETE` of a line less than 18 months old, and any `TRUNCATE`.
- **`migrate:fresh` still works,** because it drops the table. The trigger function is created with `CREATE OR REPLACE` because `migrate:fresh` drops tables but not functions; the first version failed on the second test run for that reason.
- **Tests that need to tamper with a line** turn the trigger off around the change: `ALTER TABLE activity_logs DISABLE TRIGGER activity_logs_append_only`.

## What was done, in order

| Step               | What                                                                                                                                            |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Review             | A full review: bugs, confusing parts, UI and UX problems, missing features. Four suspicions were confirmed with throwaway tests, since deleted. |
| v1.6               | The review items the owner picked, one at a time, and the README brought up to date.                                                            |
| v1.7               | A strict activity log.                                                                                                                          |
| v1.8               | A security audit of the whole project, then the fixes the owner picked, one at a time.                                                          |
| Planned, not built | Malaysia's PDPA (the owner wants it later) and a TV "now calling" screen (cancelled by the owner).                                              |

### v1.6: fixes from the review

| Fix                                                     | How                                                                                                                                                                                                                                               |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A group checked in early can be moved off a broken lane | `Booking::scopeMovable()` and `isMovable()`: still to check in, or checked in with none of its lane time begun. `moveLanes()`, the lane board, the Reservations page, the dashboard and the repair toast all use them.                            |
| Phone numbers need at least 10 digits                   | `PhoneValidationRules::MIN_PHONE_DIGITS`, and `minLength` on `PhoneInput`. A walk-in's phone is still optional. Many landlines outside the Klang Valley have 9 digits and can't be entered; the owner kept 10.                                    |
| A way back to your place in line                        | `WaitlistPlaces`: opening a ticket page puts that group in an encrypted `waitlist_places` cookie (a day, last 5). The front page shows "View your place" for those still in line. It only works in the same browser.                              |
| Friendly error pages                                    | `pages/error.tsx`, through `Inertia::handleExceptionsUsing()` in `AppServiceProvider`. Shared data comes in, so the page has the business name and colours. A 500 shows Laravel's own page while `APP_DEBUG` is on. JSON requests are left alone. |
| "You are next" was wrong                                | The ticket says "No group is ahead of you". `WaitlistTicket` sends `passedOver`, so the page explains when a group that joined later was called first. Calling is first-fit, so this happens before reservations.                                 |
| README contradictions                                   | Time zone and opening hours, the iPhone push claim, the queue worker, `hold_minutes`, the gate's `/branding/cover`, and more.                                                                                                                     |

**Dropped by the owner:**

- What happens to a group playing on a lane that is closed for repair ("nevermind").
- The TV screen, planned and then cancelled.

### v1.7: the activity log

**The owner's choices:**

- Log everything.
- Edits refused by the database, plus a chain of fingerprints.
- Admins only.
- Kept for 18 months.

**How it works:**

- **Writing:** `ActivityLog::record()` writes a line in the same transaction as the action. It takes a PostgreSQL advisory lock, so lines are chained one at a time.
- **The chain:** each line carries an HMAC-SHA256 fingerprint of itself and of the line before it. The key is `APP_KEY`. `verify()` walks the chain.
- **Who did it:**
    - a signed-in user;
    - a customer, by group name;
    - `asSystem('Scheduler', …)` for the scheduled jobs, or "Command line" for other commands;
    - `as()` for logins.
- **Settings:** every change goes through `Setting` model events, and only real changes are logged. `Setting::forget()` replaced the bulk deletes, because those skip model events. `Setting::save()` and `delete()` run in a transaction with their line.
- **What's covered:**
    - staff actions;
    - admin settings and users;
    - customer joins, deposits, leaving and requests;
    - scheduler calls, missed calls, no-shows and finished sessions;
    - logins.
- **Never written:** phone numbers and passwords, only that they changed.
- **Retention:** `activity-log:prune` runs daily at 4:00 AM venue time and removes lines older than 18 months, which is the only removal the database allows. `activity-log:verify` checks the chain from the command line.
- **The page:** **Settings → Activity log** (`settings/activity-log`), admins only. It has filters for dates on the venue's clock, person, kind and words, and a "Check nothing has been changed" button.
- **Watch out:**
    - Changing `APP_KEY` makes every older line fail the check.
    - The app's database role (`syahmi`) is a superuser and owns the table. Someone with those credentials can switch the trigger off; see the audit.
    - Expect a line for every call, missed call and finished session.

### v1.8: security audit and fixes (not committed)

The audit read the code and config, listed every route, checked dependencies, and tested the riskiest points live on a throwaway server on port 8123 using `bowling_test`. Fixed since, each at the owner's request:

| Fix                        | How                                                                                                                                                                                                                                                                                                                                                           |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Security headers           | `App\Http\Middleware\AddSecurityHeaders`, global, so error pages get them too. See the details below.                                                                                                                                                                                                                                                         |
| Cookies                    | The session cookie is `bowling_session` (`config/session.php`). The same middleware sets `session.secure` whenever the request is HTTPS, after the trusted proxy is applied: customers through the gate get Secure cookies, and staff on the plain-HTTP venue network can still log in. **Leave `SESSION_SECURE_COOKIE` unset**; `true` would lock staff out. |
| Unused file-serving routes | `'serve' => false` on the local disk (`config/filesystems.php`), so `storage/{path}` (GET and PUT) is gone. The logo and cover have their own routes.                                                                                                                                                                                                         |
| Search engines             | The `noindex` middleware (`KeepOutOfSearchEngines`) sends `X-Robots-Tag: noindex, nofollow` on the ticket, deposit and reservation thank-you pages. `robots.txt` deliberately does **not** block them, so search engines can see the header; a comment there says why.                                                                                        |
| Ticket links expire        | The ticket page answers 410 ("This page has expired") 24 hours after the visit ends: from leaving the line, missing the call, or the last session ending (`WaitlistTicket::expired()`, `KEEP_HOURS`). Groups in line never expire. Unpaid deposit pages expire 24 hours after they were started (`WaitlistDeposits::payPageExpired()`).                       |
| Personal data in a dev log | `storage/logs/browser.log` (Laravel Boost) held 2,572 entries with 61 secret links. It was emptied, not deleted. It fills again while developing; `BOOST_BROWSER_LOGS_WATCHER=false` switches it off.                                                                                                                                                         |

**The security headers in detail:**

- `X-Frame-Options: DENY`, plus `frame-ancestors 'none'` in the policy;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: same-origin`;
- an enforced content security policy with a nonce for each response. `Vite::useCspNonce()` provides it, and the theme script in `app.blade.php` carries it;
- `X-Powered-By` removed.

**Exceptions to the policy:**

- Inline styles stay allowed: React and the brand colour need them.
- While Vite runs in dev mode, `http:` and `ws:` are allowed, because the dev server may be on an IPv6 address the policy can't name.
- Laravel's debug page for a 500 gets no policy, because its own scripts carry no nonce.
- A response that sets its own policy keeps it.

**Verification:** the pages were checked in headless Firefox with no policy violations: the front page, the join page, login, the dashboard, the lane board, the activity log and the business settings.

**A future outside script needs its address added to the policy:** for example Turnstile, a payment widget or analytics.

## Still open

**From the security audit, most serious first:**

1. **Inertia devtools leak page data in local mode.**
    - With `APP_ENV=local`, which is the default from `.env.example` and `composer setup`, `/_inertia/devtools/entries` and `/entries/{id}` answer anyone, signed out.
    - They return every recorded page's address and full props: customers' names and phones, staff emails, and ticket links. This was proven live.
    - 1,122 recordings sat in `storage/inertia-devtools`, and they are still there.
    - **Fix:** turn the devtools off by default in `config/inertia.php`. Copy the package's whole `devtools` block, because the merge is shallow. Then delete the recordings.
2. **Failed logins log whatever was typed as the email.** A password typed into the wrong box would sit in the log for 18 months. **Fix:** only write the typed value when it is an existing account's email. None had been logged at the time of the audit.
3. **The activity log's protection only binds the app's code.** The database role is a superuser and table owner, and the HMAC key is `APP_KEY` on the same machine. **Fix at a venue:** one role that owns the tables and runs migrations, and a limited role for the app.
4. **Anyone can flood the waitlist.** The stand-in Pay button takes no money and there is no bot check. The public rate limits share one counter per internet address: `throttle:5,1` keys on domain and IP, so joining, reserving and turning on notifications share it.
5. **Staff pages are on the venue network over plain HTTP, with no two-factor login.** A guest Wi-Fi on the same network can reach `/login` and capture logins.
6. **A ticket link works like a key.** Whoever sees the QR code or a shared link can press "Leave the line".
7. **Smaller items:**
    - The counter computer never logs out, because polling keeps it signed in.
    - The Users settings don't ask for the password again.
    - `npm audit` reports `shell-quote` through `concurrently`. It can't be reached, and the fix is a dependency change for the owner to approve.
    - At a venue: use nginx `limit_req` and make `.env` readable only by its owner.
    - Delete copies of personal data once they're no longer needed: the rehearsal databases and the devtools recordings.
8. **Not security:** PHP here allows uploads up to 2 MB, while the cover photo form allows 3 MB.

**From the review, not fixed:**

- **"Seated with no lane", cause found:**
    - `WaitlistService::seat()` checks that the booking is pending with a plain read, then marks the held lanes active without checking that any changed.
    - If `BookingCleanup::releaseExpiredHolds()` lands in between, the group is seated with no lane.
    - **Fix:** lock the booking, or refuse when the update touched no rows.
- **Re-oiling in a reservation's lead hour is refused.** This follows from the owner's rule about short closures, so ask before changing it.
- **Check-in and the "needs moving" flags still use different rules.** `BookingService::closedLaneNumbers()` uses "lane not open"; the lists use `needsMovingAt()`.
- **Extend session** is still offered on a lane that is out of order.
- **Place in line** still counts groups that have been called.
- **UX:**
    - after-midnight times on the reservation form;
    - the thank-you page promising a call window that has passed;
    - the front-page card saying "we will keep a lane for you";
    - one-tap Remove and Skip with no undo;
    - no phone number in the waitlist panel;
    - the Reservations page never refreshes;
    - a seated group's deposit disappears from staff view;
    - one "Closed" badge for two states.
- **Confusing parts:**
    - online reservations are saved as phone bookings;
    - late-night sessions count on the next calendar day;
    - `hold_minutes` is never used;
    - bumpers and the "Blocked" lane state can't be set;
    - `REPAIRS_RECORDED_SINCE` is fixed to the owner's Mac;
    - iPhone push needs a web app manifest.
- **Missing features, for later:**
    - league nights and lane blocks;
    - big walk-in groups as one unit;
    - holiday hours;
    - a confirmation page for reservations;
    - a walk-in's time starts when called, not when seated;
    - no "last call" for online joins.

**From before 1.0, still open:**

- **The scheduler can stall for a day.** `withoutOverlapping()` uses its default 24-hour lock.
- **The per-phone limit can be dodged** when the deposit is on.
- **A finished group's page keeps refreshing** every 5 seconds until it expires.
- **A walk-in misses a gap exactly as long as its session.**
- **Smaller ones:**
    - a started reservation can't be edited without moving it later;
    - a request minutes away counts as missed at once;
    - a call window can't cross midnight;
    - opening hours can't cover a whole day;
    - unpaid deposit rows are never removed (their pages now expire, but the rows stay).
- **Indexes:** `lane_allocations` has no index on `starts_at`.
- **Android home-screen icon:** it needs a web app manifest.
- **Time zones:** the Reservations page reads its days in the device's time zone.

## Plans waiting for the owner

- **PDPA (Malaysia's personal data law):**
    - **The plan:**
        1. A privacy notice in Bahasa Malaysia and English. Section 7 requires both.
        2. A required tick box on the online forms.
        3. Customer names and phones anonymised after a set time.
        4. An admin page to find, correct or remove a person's data.
    - **Open decisions:**
        - how long to keep customer details (6 months was suggested);
        - how the activity log should handle customer names;
        - whether the tick box is required;
        - whether to start with steps 1 and 2.
    - **Don't build it unasked.**
- **TV "now calling" screen:** planned and cancelled. Don't build it unasked.

## Decisions from the owner

This session:

- **Fix review items one by one, as the owner names them.** Don't run ahead through the list.
- **Phone numbers:** at least 10 digits.
- **Activity log:**
    - log everything;
    - database-enforced plus a fingerprint chain;
    - admins only;
    - 18 months.
- **Versions:** v1.6, then v1.7. The owner first wrote "1.4" and meant 1.7.
- **A group playing on a lane closed for repair:** dropped ("nevermind").
- **TV screen:** cancelled.
- **PDPA:** later.
- **Security:** the owner picked fixes one at a time. Headers, the Boost log, cookies, file-serving routes, and search engines with expiry are done.

Still in force from earlier handoffs:

- **The owner commits and names versions.** Do not commit. When the owner names a version, set `bowling.version` to it.
- **A batch of similar changes** goes one at a time, with a stop after each.
- **Formatting:** do not run Pint over `BookingService.php` or `WaitlistService.php`, and do not fix the existing PHPStan errors yet. Format other files by path.
- **Code rules:**
    - never edit a migration that has run;
    - keep business logic in services;
    - never insert into `lane_allocations` from a controller;
    - enums go to the frontend as strings;
    - money is integer cents.
- **Live updates and alerts:** live updates stay as polling. Alert a called group by web push, not SMS.
- **Network split:** staff pages stay on the venue's private network. Customer pages are public through the nginx gate in the README, and any new public address must be added to it.
- **Parties still waiting at closing time** are still called afterwards. Do not rebuild a closing-time cut-off without asking.
- **No big features** unless the owner asks.
- **This file:** the owner removes `HANDOFF.md` files after reading. Check before committing this one.

## Before going live

- **Environment:**
    - [ ] `APP_ENV=production`, `APP_DEBUG=false`, and `composer install --no-dev`, which removes Boost.
    - [ ] Turn the Inertia devtools off, as in audit item 1.
    - [ ] `BOWLING_PUBLIC_URL` set to the public HTTPS address, and `TRUSTED_PROXIES` set as in the README.
    - [ ] Leave `SESSION_SECURE_COOKIE` unset.
    - [ ] Keep `APP_KEY` fixed and secret: it signs the activity log.
- **The gate and the network:**
    - [ ] The nginx gate from the README, which now includes `/branding/cover` and `server_tokens off;`. Add `limit_req`.
    - [ ] A guest Wi-Fi kept apart from the staff computers, or HTTPS for staff pages.
- **Database and money:**
    - [ ] A limited database role for the app, separate from the role that owns the tables.
    - [ ] A real payment provider, or a decision about the stand-in, and a "not a robot" check before the online waitlist is public.
- **Other:**
    - [ ] Save a business name under **Settings → Business**.
    - [ ] Run the scheduler, as in the README's "Going live".

## How the code is laid out (new since v1.5)

| Where                                                               | What                                                                                                                                                             |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Booking::scopeMovable()`, `isMovable()`                            | Whether a reservation can still be moved to other lanes                                                                                                          |
| `WaitlistPlaces`                                                    | The `waitlist_places` cookie and the front page's "View your place"                                                                                              |
| `pages/error.tsx`, `AppServiceProvider::configureErrorPages()`      | Friendly error pages for 403, 404, 410, 419, 429, 500 and 503                                                                                                    |
| `ActivityLog`, `ActivityLogEntry`, `activity_logs`                  | The activity log: writing, the chain, verifying, removing old lines                                                                                              |
| `Settings\ActivityLogController`, `pages/settings/activity-log.tsx` | The admin page                                                                                                                                                   |
| `PruneActivityLog`, `VerifyActivityLog`                             | `activity-log:prune` (daily) and `activity-log:verify`                                                                                                           |
| `Setting` (model events, `forget()`)                                | Every setting change logged with its old and new value                                                                                                           |
| `AppServiceProvider::logSignIns()`                                  | Logins, failed logins, logouts                                                                                                                                   |
| `AddSecurityHeaders`                                                | Security headers, the content security policy and nonce, Secure cookies on HTTPS                                                                                 |
| `KeepOutOfSearchEngines` (`noindex`)                                | `X-Robots-Tag` on the pages reached by a secret link                                                                                                             |
| `WaitlistTicket::expired()`, `WaitlistDeposits::payPageExpired()`   | When a ticket or deposit page has expired                                                                                                                        |
| Tests                                                               | `ActivityLogTest`, `ActivityLogSettingsTest`, `SecurityHeadersTest`, `SearchEnginesTest`, `StorageRoutesTest`, `ErrorPageTest`, plus additions to existing files |

## How things were checked

- **Tests:** they run on `bowling_test`. Every change has feature tests, and the full suite passed after each step.
- **Throwaway probe tests:** four confirmed the review's suspicions before anything was fixed:
    - the seat race;
    - re-oiling refused in the lead hour;
    - an early check-in stuck on a broken lane;
    - "you are next".

    They were deleted afterwards.

- **Live checks:** the app was served on the test database with `DB_DATABASE=bowling_test php artisan serve --port=8123 --no-reload`.
    - The devtools leak was proven there.
    - The security policy was checked there in headless Firefox, driven over Marionette (port 2828) by a short Node script in the session scratchpad, which is gone. A throwaway admin, two lanes and a brand colour were seeded on `bowling_test` for that.
- **The migration** was rehearsed on a copy, as described above.
- **Never done:** a notification on a real phone, a real payment, and the public gate with a real tunnel and HTTPS.
