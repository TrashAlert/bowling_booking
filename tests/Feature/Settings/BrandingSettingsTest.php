<?php

use App\Models\User;
use App\Services\Branding;
use App\Services\OpeningHours;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('branding.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the business settings', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), ['name' => 'Hijacked Lanes']);

    $response->assertForbidden();
    expect(app(Branding::class)->name())->toBe(config('app.name'));
})->with([
    'open the page' => ['get', 'branding.edit'],
    'change the details' => ['post', 'branding.update'],
]);

test('until a name is saved the business goes by the app name and has no logo', function () {
    config(['app.name' => 'Strike Zone']);

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('branding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/branding')
        ->where('businessName', 'Strike Zone')
        ->where('businessLogoUrl', null)
        ->where('maxLogoKilobytes', 1024)
        ->where('name', 'Strike Zone')
        ->where('logoUrl', null));
});

test('admins can rename the business and every page then uses the new name', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->post(route('branding.update'), ['name' => 'Kingpin Bowl']);

    $response->assertRedirect(route('branding.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Business details saved.']);
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('name', 'Kingpin Bowl'))
        ->assertSee('<title>Kingpin Bowl</title>', false)
        ->assertSee('<meta name="app-name" content="Kingpin Bowl">', false);
});

test('admins can upload a logo, which anyone can then load', function () {
    $logo = UploadedFile::fake()->image('logo.png', 300, 300);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => $logo])
        ->assertSessionHasNoErrors();

    $path = app(Branding::class)->logoPath();
    Storage::disk('local')->assertExists($path);
    $url = app(Branding::class)->logoUrl();
    expect($url)->toStartWith('/branding/logo?v=');
    auth()->logout();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('logoUrl', $url));
});

test('a new logo replaces the old one and gets a new address', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('old.png')]);
    $oldPath = app(Branding::class)->logoPath();
    $oldUrl = app(Branding::class)->logoUrl();

    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('new.jpg')]);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists(app(Branding::class)->logoPath());
    expect(app(Branding::class)->logoUrl())->not->toBe($oldUrl);
});

test('saving without a new logo keeps the one in use', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('logo.png')]);
    $path = app(Branding::class)->logoPath();

    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowling Centre', 'remove_logo' => '0']);

    expect(app(Branding::class)->logoPath())->toBe($path)
        ->and(app(Branding::class)->name())->toBe('Kingpin Bowling Centre');
});

test('admins can remove the logo', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('logo.png')]);
    $path = app(Branding::class)->logoPath();

    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'remove_logo' => '1']);

    Storage::disk('local')->assertMissing($path);
    expect(app(Branding::class)->logoUrl())->toBeNull();
    $this->get(route('branding.logo'))->assertNotFound();
});

test('the logo address returns 404 while the business has no logo', function () {
    $response = $this->get(route('branding.logo'));

    $response->assertNotFound();
});

test('business details that are not acceptable are refused', function (Closure $payload, string $field, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())->post(route('branding.update'), $payload());

    $response->assertSessionHasErrors([$field => $message]);
    expect(app(Branding::class)->name())->toBe(config('app.name'))
        ->and(app(Branding::class)->logoPath())->toBeNull();
})->with([
    'no name' => [fn () => ['name' => ''], 'name', 'The business name field is required.'],
    'a name that is too long' => [fn () => ['name' => str_repeat('a', 61)], 'name', 'The business name field must not be greater than 60 characters.'],
    'a logo that is not a picture' => [
        fn () => ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')],
        'logo',
        'The logo must be a PNG, JPG or WebP picture.',
    ],
    'an SVG logo, which could carry a script' => [
        fn () => ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'logo',
        'The logo must be a PNG, JPG or WebP picture.',
    ],
    'a logo file that is too big' => [
        fn () => ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('logo.png')->size(1025)],
        'logo',
        'The logo can be up to 1 MB.',
    ],
    'a logo that is too large in pixels' => [
        fn () => ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('logo.png', 2001, 100)],
        'logo',
        'The logo can be up to 2000 pixels wide and tall.',
    ],
]);

test('admins see the currency symbol and time zone in use, and the zones to pick from', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('branding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('venue', ['currencySymbol' => 'RM', 'timezone' => 'Asia/Kuala_Lumpur'])
        ->where('timezones', fn ($zones) => $zones->contains('Asia/Kuala_Lumpur') && $zones->contains('Europe/London')));
});

test('admins can change the currency symbol shown in front of money', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'currency_symbol' => 'S$', 'timezone' => 'Asia/Singapore'])
        ->assertSessionHasNoErrors();

    $this->get(route('waitlist.join'))->assertInertia(fn (Assert $page) => $page->where('currencySymbol', 'S$'));
});

test('admins can change the time zone, which the opening hours are then read in', function () {
    openDaily('10:00', '23:00');
    // 11:00 in Kuala Lumpur, and 04:00 in London.
    $this->travelTo('2026-10-05 03:00:00');
    expect(app(OpeningHours::class)->isOpenAt(now()))->toBeTrue();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'currency_symbol' => 'RM', 'timezone' => 'Europe/London'])
        ->assertSessionHasNoErrors();

    expect(app(OpeningHours::class)->isOpenAt(now()))->toBeFalse();
    $this->get(route('opening-hours.edit'))->assertInertia(fn (Assert $page) => $page->where('timezone', 'Europe/London'));
});

test('saving only the name leaves the currency symbol and time zone as they were', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl'])
        ->assertSessionHasNoErrors();

    expect(config('bowling.currency_symbol'))->toBe('RM')
        ->and(config('bowling.timezone'))->toBe('Asia/Kuala_Lumpur');
});

test('a currency symbol or time zone that is not acceptable is refused', function (array $payload, string $field, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'currency_symbol' => 'RM', 'timezone' => 'Asia/Kuala_Lumpur', ...$payload]);

    $response->assertSessionHasErrors([$field => $message]);
    expect(config('bowling.currency_symbol'))->toBe('RM')
        ->and(config('bowling.timezone'))->toBe('Asia/Kuala_Lumpur');
})->with([
    'no currency symbol' => [['currency_symbol' => ''], 'currency_symbol', 'The currency symbol field is required.'],
    'a currency symbol that is too long' => [['currency_symbol' => 'RINGGIT'], 'currency_symbol', 'The currency symbol field must not be greater than 5 characters.'],
    'no time zone' => [['timezone' => ''], 'timezone', 'The time zone field is required.'],
    'a time zone that does not exist' => [['timezone' => 'Mars/Phobos'], 'timezone', 'The time zone field must be a valid timezone.'],
]);

test('the business has no contact details until an admin fills them in', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('branding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('contact', ['phone' => null, 'address' => null, 'mapUrl' => null]));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('contact', ['phone' => null, 'address' => null, 'mapUrl' => null]));
});

test('admins can set the contact details, which the customer pages then show', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), [
            'name' => 'Kingpin Bowl',
            'phone' => '+60 3-1234 5678',
            'address' => "Level 3, Example Mall\n50000 Kuala Lumpur",
            'map_url' => 'https://maps.example.com/kingpin',
        ])
        ->assertSessionHasNoErrors();

    $contact = [
        'phone' => '+60 3-1234 5678',
        'address' => "Level 3, Example Mall\n50000 Kuala Lumpur",
        'mapUrl' => 'https://maps.example.com/kingpin',
    ];
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('contact', $contact));
    $this->get(route('reserve'))->assertInertia(fn (Assert $page) => $page->where('contact', $contact));
});

test('emptying a contact detail takes it off the pages and leaves the others', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('branding.update'), [
        'name' => 'Kingpin Bowl', 'phone' => '0312345678', 'address' => 'Example Mall', 'map_url' => 'https://maps.example.com/kingpin',
    ]);

    $this->actingAs($admin)->post(route('branding.update'), [
        'name' => 'Kingpin Bowl', 'phone' => '', 'address' => 'Example Mall', 'map_url' => '',
    ])->assertSessionHasNoErrors();

    expect(app(Branding::class)->contact())->toBe(['phone' => null, 'address' => 'Example Mall', 'mapUrl' => null]);
});

test('saving without the contact fields leaves the contact details as they were', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'phone' => '0312345678']);

    $this->actingAs($admin)->post(route('branding.update'), ['name' => 'Kingpin Bowling Centre'])->assertSessionHasNoErrors();

    expect(app(Branding::class)->contact()['phone'])->toBe('0312345678');
});

test('a contact detail that is not acceptable is refused', function (array $payload, string $field, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', ...$payload]);

    $response->assertSessionHasErrors([$field => $message]);
    expect(app(Branding::class)->contact())->toBe(['phone' => null, 'address' => null, 'mapUrl' => null]);
})->with([
    'a phone number with letters' => [['phone' => 'call us'], 'phone', 'The phone number can only contain digits, spaces and + ( ) -.'],
    'a phone number that is too long' => [['phone' => str_repeat('1', 31)], 'phone', 'The phone number field must not be greater than 30 characters.'],
    'an address that is too long' => [['address' => str_repeat('a', 201)], 'address', 'The address field must not be greater than 200 characters.'],
    'a map link that is not a web address' => [['map_url' => 'opposite the station'], 'map_url', 'The map link must be a web address starting with http:// or https://.'],
    'a map link that would run a script' => [['map_url' => 'javascript:alert(1)'], 'map_url', 'The map link must be a web address starting with http:// or https://.'],
]);

test('there is no brand colour until an admin picks one', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('branding.edit'));

    $response->assertInertia(fn (Assert $page) => $page->where('brandColor', null));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('brand', null));
});

test('admins can pick a brand colour, which the customer pages use with text that reads on it', function (string $picked, string $color, string $textColor) {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'brand_color' => $picked])
        ->assertSessionHasNoErrors();

    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->where('brand', ['color' => $color, 'textColor' => $textColor]));
})->with([
    'a dark blue, with white text' => ['#1D4ED8', '#1d4ed8', '#ffffff'],
    'a bright yellow, with dark text' => ['#facc15', '#facc15', '#0a0a0a'],
]);

test('emptying the brand colour goes back to the plain black and white', function () {
    app(Branding::class)->setBrandColor('#1d4ed8');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'brand_color' => ''])
        ->assertSessionHasNoErrors();

    expect(app(Branding::class)->brandColor())->toBeNull();
});

test('a brand colour that is not a plain colour code is refused', function (string $color) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'brand_color' => $color]);

    $response->assertSessionHasErrors(['brand_color' => 'The brand colour must be a colour like #1d4ed8.']);
    expect(app(Branding::class)->brandColor())->toBeNull();
})->with([
    'a colour name' => ['red'],
    'too few digits' => ['#12345'],
    'something written into the page\'s styles' => ['#000; } body { display: none'],
]);

test('the business has no social media accounts until an admin fills them in', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('branding.edit'));

    $none = ['whatsapp' => null, 'instagram' => null, 'facebook' => null, 'tiktok' => null];
    $response->assertInertia(fn (Assert $page) => $page->where('social', $none));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('social', $none));
});

test('a social media account can be typed in the natural way, and the customer pages link to it', function (string $network, string $typed, string $kept, string $link) {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', $network => $typed])
        ->assertSessionHasNoErrors();
    auth()->logout();

    expect(app(Branding::class)->social()[$network])->toBe($kept);
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where("social.{$network}", $link));
})->with([
    'a WhatsApp number with spaces and dashes' => ['whatsapp', '+60 12-345 6789', '60123456789', 'https://wa.me/60123456789'],
    'an Instagram name with @' => ['instagram', '@kingpin.bowl', 'kingpin.bowl', 'https://www.instagram.com/kingpin.bowl/'],
    'an Instagram link' => ['instagram', 'https://www.instagram.com/kingpin.bowl/?hl=en', 'kingpin.bowl', 'https://www.instagram.com/kingpin.bowl/'],
    'a TikTok link' => ['tiktok', 'https://www.tiktok.com/@kingpinbowl', 'kingpinbowl', 'https://www.tiktok.com/@kingpinbowl'],
    'a Facebook page link from a phone' => ['facebook', 'https://m.facebook.com/kingpinbowl', 'kingpinbowl', 'https://www.facebook.com/kingpinbowl'],
    'a Facebook profile with no page name' => ['facebook', 'https://www.facebook.com/profile.php?id=100012345678', 'profile.php?id=100012345678', 'https://www.facebook.com/profile.php?id=100012345678'],
]);

test('emptying a social media account takes it off the pages', function () {
    app(Branding::class)->setSocial('instagram', '@kingpinbowl');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'instagram' => ''])
        ->assertSessionHasNoErrors();

    expect(app(Branding::class)->social()['instagram'])->toBeNull();
});

test('a social media account that is not one is refused', function (string $network, string $typed, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', $network => $typed]);

    $response->assertSessionHasErrors([$network => $message]);
    expect(app(Branding::class)->social()[$network])->toBeNull();
})->with([
    'a WhatsApp number with words' => ['whatsapp', 'call us', 'The WhatsApp number must be a phone number with its country code, such as +60 12-345 6789.'],
    'an Instagram link to another site' => ['instagram', 'https://evil.example.com/kingpinbowl', 'The Instagram account must be a name such as @kingpinbowl, or a link to it on instagram.com.'],
    'a Facebook link that would run a script' => ['facebook', 'javascript:alert(1)', 'The Facebook page must be a link to it on facebook.com.'],
    'a Facebook address with markup' => ['facebook', 'https://www.facebook.com/<script>', 'The Facebook page must be a link to it on facebook.com.'],
    'a TikTok name with a space' => ['tiktok', 'kingpin bowl', 'The TikTok account must be a name such as @kingpinbowl, or a link to it on tiktok.com.'],
]);

test('the browser tab and a phone\'s home screen show the logo once one is uploaded', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('branding.update'), ['name' => 'Kingpin Bowl', 'logo' => UploadedFile::fake()->image('logo.png', 300, 300)]);
    auth()->logout();

    $response = $this->get(route('home'));

    $url = e(app(Branding::class)->logoUrl());
    $response->assertSee("<link rel=\"icon\" href=\"{$url}\">", false)
        ->assertSee("<link rel=\"apple-touch-icon\" href=\"{$url}\">", false)
        ->assertDontSee('/favicon.svg', false);
});

test('the browser tab keeps the plain icon while the business has no logo', function () {
    $response = $this->get(route('home'));

    $response->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
        ->assertDontSee('/branding/logo', false);
});
