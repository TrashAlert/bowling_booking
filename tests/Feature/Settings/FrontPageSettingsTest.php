<?php

use App\Models\User;
use App\Services\FrontPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('front-page.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the front page words', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['tagline' => 'Hijacked']);

    $response->assertForbidden();
    expect(app(FrontPage::class)->saved()['tagline'])->toBeNull();
})->with([
    'open the page' => ['get', 'front-page.edit'],
    'change the words' => ['post', 'front-page.update'],
]);

test('the front page uses the plain words until an admin writes its own', function () {
    $settings = $this->actingAs(User::factory()->admin()->create())->get(route('front-page.edit'));
    auth()->logout();

    $home = $this->get(route('home'));

    $settings->assertInertia(fn (Assert $page) => $page
        ->component('settings/front-page')
        ->where('texts.tagline', null)
        ->where('defaults.tagline', 'Come and bowl. Walk in, or book a lane ahead.')
        ->where('limits.tagline', 120));
    $home->assertInertia(fn (Assert $page) => $page
        ->where('texts.tagline', 'Come and bowl. Walk in, or book a lane ahead.')
        ->where('texts.waitlistButton', 'Join the waitlist')
        ->where('texts.reserveTitle', 'Reserve a lane'));
});

test('admins can write the front page\'s words, which anyone then sees', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->post(route('front-page.update'), [
        'tagline' => '  Twelve lanes, open late.  ',
        'waitlist_button' => 'Get in line',
        'reserve_title' => 'Plan a party',
    ]);
    auth()->logout();

    $response->assertRedirect(route('front-page.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Front page saved.']);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('texts.tagline', 'Twelve lanes, open late.')
        ->where('texts.waitlistButton', 'Get in line')
        ->where('texts.reserveTitle', 'Plan a party')
        ->where('texts.reserveButton', 'Make a reservation'));
});

test('emptying a text goes back to the plain words', function () {
    app(FrontPage::class)->save(['tagline' => 'Twelve lanes, open late.']);

    $this->actingAs(User::factory()->admin()->create())->post(route('front-page.update'), ['tagline' => '']);

    expect(app(FrontPage::class)->texts()['tagline'])->toBe('Come and bowl. Walk in, or book a lane ahead.');
});

test('a text longer than its limit is refused', function (string $field, int $length, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), [$field => str_repeat('a', $length)]);

    $response->assertSessionHasErrors([$field => $message]);
    expect(array_filter(app(FrontPage::class)->saved()))->toBe([]);
})->with([
    'the line under the name' => ['tagline', 121, 'The line under the name field must not be greater than 120 characters.'],
    'a card description' => ['reserve_text', 201, 'The reservation card description field must not be greater than 200 characters.'],
    'a button' => ['waitlist_button', 31, 'The waitlist card button field must not be greater than 30 characters.'],
]);

test('admins can upload a cover photo, which anyone can then load', function () {
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), ['cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900)])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $url = app(FrontPage::class)->coverUrl();
    Storage::disk('local')->assertExists(app(FrontPage::class)->coverPath());
    expect($url)->toStartWith('/branding/cover?v=');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('coverUrl', $url));
});

test('a new cover photo replaces the old one and gets a new address', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('front-page.update'), ['cover' => UploadedFile::fake()->image('old.jpg')]);
    $oldPath = app(FrontPage::class)->coverPath();
    $oldUrl = app(FrontPage::class)->coverUrl();

    $this->actingAs($admin)->post(route('front-page.update'), ['cover' => UploadedFile::fake()->image('new.png')]);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists(app(FrontPage::class)->coverPath());
    expect(app(FrontPage::class)->coverUrl())->not->toBe($oldUrl);
});

test('saving the words without a new photo keeps the cover in use', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('front-page.update'), ['cover' => UploadedFile::fake()->image('cover.jpg')]);
    $path = app(FrontPage::class)->coverPath();

    $this->actingAs($admin)->post(route('front-page.update'), ['tagline' => 'Open late.', 'remove_cover' => '0']);

    expect(app(FrontPage::class)->coverPath())->toBe($path)
        ->and(app(FrontPage::class)->texts()['tagline'])->toBe('Open late.');
});

test('admins can remove the cover photo', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('front-page.update'), ['cover' => UploadedFile::fake()->image('cover.jpg')]);
    $path = app(FrontPage::class)->coverPath();

    $this->actingAs($admin)->post(route('front-page.update'), ['remove_cover' => '1']);

    Storage::disk('local')->assertMissing($path);
    expect(app(FrontPage::class)->coverUrl())->toBeNull();
    $this->get(route('front-page.cover'))->assertNotFound();
});

test('a cover photo that is not acceptable is refused', function (Closure $file, string $message) {
    Storage::fake('local');

    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), ['cover' => $file()]);

    $response->assertSessionHasErrors(['cover' => $message]);
    expect(app(FrontPage::class)->coverPath())->toBeNull();
})->with([
    'a drawing that can carry a script' => [fn () => UploadedFile::fake()->create('cover.svg', 10, 'image/svg+xml'), 'The cover photo must be a PNG, JPG or WebP picture.'],
    'a document' => [fn () => UploadedFile::fake()->create('cover.pdf', 10, 'application/pdf'), 'The cover photo must be a PNG, JPG or WebP picture.'],
    'a file over 3 MB' => [fn () => UploadedFile::fake()->image('cover.jpg')->size(3073), 'The cover photo can be up to 3 MB.'],
    'a photo over 4000 pixels wide' => [fn () => UploadedFile::fake()->image('cover.jpg', 4001, 1000), 'The cover photo can be up to 4000 pixels wide and tall.'],
]);

test('the front page lists the week\'s opening hours, with today, once they are set', function () {
    $this->travelTo(venueTime('2026-10-07 14:00'));
    openDaily('10:00', '23:00', except: [0 => null]);

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('openingHours.today', 3)
        ->has('openingHours.days', 7)
        ->where('openingHours.days.0', ['weekday' => 1, 'opens' => '10:00', 'closes' => '23:00'])
        ->where('openingHours.days.6', ['weekday' => 0, 'opens' => null, 'closes' => null]));
});

test('the front page lists no opening hours while none are set', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('openingHours', null));
});

test('admins can hide the opening hours from the front page', function () {
    openDaily();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), ['show_opening_hours' => '0'])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('openingHours', null));
    expect(app(FrontPage::class)->showsOpeningHours())->toBeFalse();
});

test('admins see whether the opening hours are shown, and whether any are set', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('front-page.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('showOpeningHours', true)
        ->where('openingHoursSet', false));
});

test('there is no announcement until an admin writes one', function () {
    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('announcement', null));
});

test('an announcement is shown across the customer pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), ['announcement' => '  Closed for Hari Raya, 10-12 April.  ', 'announcement_until' => ''])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('announcement', 'Closed for Hari Raya, 10-12 April.'));
    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page->where('announcement', 'Closed for Hari Raya, 10-12 April.'));
    $this->get(route('reserve'))->assertInertia(fn (Assert $page) => $page->where('announcement', 'Closed for Hari Raya, 10-12 April.'));
});

test('an announcement with a last day is shown through the end of that day on the venue\'s clock', function (string $now, ?string $shown) {
    $this->travelTo(venueTime('2026-04-08 12:00'));
    app(FrontPage::class)->announce('Closed for Hari Raya.', '2026-04-12');
    $this->travelTo(venueTime($now));

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('announcement', $shown));
})->with([
    'on the last day, late at night' => ['2026-04-12 23:59', 'Closed for Hari Raya.'],
    'the next morning' => ['2026-04-13 00:01', null],
]);

test('emptying the announcement takes it down', function () {
    app(FrontPage::class)->announce('Closed for Hari Raya.', '2099-04-12');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('front-page.update'), ['announcement' => '', 'announcement_until' => '2099-04-12']);

    expect(app(FrontPage::class)->announcement())->toBe(['message' => null, 'until' => null]);
});

test('an announcement that is too long, or ends in the past, is refused', function (array $form, string $field, string $message) {
    $this->travelTo(venueTime('2026-04-08 12:00'));

    $response = $this->actingAs(User::factory()->admin()->create())->post(route('front-page.update'), $form);

    $response->assertSessionHasErrors([$field => $message]);
    expect(app(FrontPage::class)->announcement()['message'])->toBeNull();
})->with([
    'too long' => [['announcement' => str_repeat('a', 201)], 'announcement', 'The announcement field must not be greater than 200 characters.'],
    'a last day already over' => [['announcement' => 'Closed.', 'announcement_until' => '2026-04-07'], 'announcement_until', 'The last day to show it can\'t be in the past.'],
]);
