<?php

use App\Enums\UserRole;
use App\Models\ActivityLogEntry;
use App\Models\Lane;
use App\Models\User;
use App\Services\ActivityLog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->travelTo('2026-10-01 18:00:00'));

test('only admins can read the activity log', function (?Closure $user, Closure $assert) {
    $request = $user === null ? $this : $this->actingAs($user());

    $response = $request->get(route('activity-log.index'));

    $assert($response);
})->with([
    'an admin' => [fn () => User::factory()->admin()->create(), fn ($response) => $response->assertOk()],
    'counter staff' => [fn () => User::factory()->staff()->create(), fn ($response) => $response->assertForbidden()],
    'nobody signed in' => [null, fn ($response) => $response->assertRedirect(route('login'))],
]);

test('the log is shown newest first, with who did what', function () {
    $admin = User::factory()->admin()->create(['name' => 'Aisyah']);
    $this->actingAs($admin)->post(route('staff.lanes.closure.store', Lane::factory()->create(['number' => 3])), ['reason' => 'repair', 'days' => 0]);
    $this->travel(1)->minutes();
    $this->post(route('staff.lanes.closure.destroy', Lane::query()->sole()), ['_method' => 'DELETE']);

    $response = $this->get(route('activity-log.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/activity-log')
        ->where('page.total', 2)
        ->where('entries.0.summary', 'Reopened lane 3 after repair.')
        ->where('entries.0.actorType', 'user')
        ->where('entries.0.actorName', 'Aisyah')
        ->where('entries.0.ipAddress', '127.0.0.1')
        ->where('entries.1.summary', 'Closed lane 3 for repair, until reopened.'));
});

test('the log can be narrowed by kind, person, words and days', function (array $filter, array $summaries) {
    $aisyah = User::factory()->admin()->create(['name' => 'Aisyah']);
    $lane = Lane::factory()->create(['number' => 3]);
    $this->actingAs($aisyah)->post(route('staff.lanes.closure.store', $lane), ['reason' => 're_oil', 'minutes' => 30]);
    $this->travel(1)->days();
    $this->actingAs(User::factory()->staff()->create(['name' => 'Ben']))->post(route('staff.waitlist.store'), [
        'name' => 'Farah', 'party_size' => 2, 'minutes' => 60,
    ]);
    $filter = collect($filter)->map(fn ($value) => $value === 'aisyah' ? "user:{$aisyah->id}" : $value)->all();

    $response = $this->actingAs($aisyah)->get(route('activity-log.index', $filter));

    $response->assertInertia(fn (Assert $page) => $page->where('entries', fn ($entries) => collect($entries)->pluck('summary')->all() === $summaries));
})->with([
    'by kind' => [['kind' => 'lanes'], ['Closed lane 3 for re-oil, 30 minutes, from 2:00 AM to 2:30 AM.']],
    'by person' => [['who' => 'aisyah'], ['Closed lane 3 for re-oil, 30 minutes, from 2:00 AM to 2:30 AM.']],
    'by words' => [['search' => 'farah'], ["Farah's group (2 people) joined the line, for 1 hour."]],
    'by days on the venue clock' => [['from' => '2026-10-03', 'to' => '2026-10-03'], ["Farah's group (2 people) joined the line, for 1 hour."]],
]);

test('checking the log says whether every line still holds up', function (bool $tampered, string $type, string $message) {
    $admin = User::factory()->admin()->create();
    app(ActivityLog::class)->record('test.line', 'A line.');
    $line = ActivityLogEntry::query()->sole()->id;

    if ($tampered) {
        DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER activity_logs_append_only');
        DB::table('activity_logs')->update(['summary' => 'Rewritten.']);
        DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER activity_logs_append_only');
    }

    $response = $this->actingAs($admin)->post(route('activity-log.verify'));

    $response->assertInertiaFlash('toast', ['type' => $type, 'message' => str_replace(':line', (string) $line, $message)]);
})->with([
    'untouched' => [false, 'success', 'All 1 line checks out. Nothing has been changed.'],
    'changed in the database' => [true, 'error', 'Line :line does not match its fingerprint. The log was changed outside the app from there on.'],
]);

test('a customer joining online is logged as the customer, by name', function () {
    Lane::factory()->create();

    $this->post(route('waitlist.store'), ['name' => 'Farah', 'phone' => '0123456789', 'party_size' => 2, 'minutes' => 60]);

    $joined = ActivityLogEntry::query()->where('action', 'waitlist.joined')->sole();

    expect([$joined->actor_type, $joined->actor_name])->toBe(['customer', 'Farah']);
});

test('logging in, a failed attempt and logging out are logged', function () {
    $user = User::factory()->staff()->create(['name' => 'Ben', 'email' => 'ben@example.com']);
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'wrong']);
    $this->post(route('login.store'), ['email' => 'ben@example.com', 'password' => 'password']);

    $this->post(route('logout'));

    expect(ActivityLogEntry::query()->orderBy('id')->get()->map(fn ($entry) => [$entry->action, $entry->actor_type, $entry->actor_name])->all())->toBe([
        ['auth.login_failed', 'unknown', 'ben@example.com'],
        ['auth.logged_in', 'user', 'Ben'],
        ['auth.logged_out', 'user', 'Ben'],
    ]);
});

test('an admin changing an account is logged without the password', function () {
    $admin = User::factory()->admin()->create();
    $ben = User::factory()->staff()->create(['name' => 'Ben', 'email' => 'ben@example.com']);

    $this->actingAs($admin)->patch(route('users.update', $ben), [
        'name' => 'Ben', 'email' => 'ben@example.com', 'role' => UserRole::Admin->value, 'password' => 'a-new-password-1',
    ]);

    $changed = ActivityLogEntry::query()->where('action', 'user.changed')->sole();
    expect($changed->changes)->toBe(['Role' => ['staff', 'admin'], 'Password' => ['old password', 'new password']])
        ->and(DB::table('activity_logs')->whereLike('changes', '%a-new-password-1%')->exists())->toBeFalse();
});
