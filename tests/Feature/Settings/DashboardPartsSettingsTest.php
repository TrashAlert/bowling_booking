<?php

use App\Models\User;
use App\Services\DashboardSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The form an admin sends, with every part on except those named.
 *
 * @return array{parts: array<string, string>}
 */
function dashboardPartsForm(string ...$off): array
{
    return ['parts' => array_combine(
        DashboardSettings::PARTS,
        array_map(fn (string $part) => in_array($part, $off, true) ? '0' : '1', DashboardSettings::PARTS),
    )];
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard-parts.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the dashboard settings', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), dashboardPartsForm('rightNow'));

    $response->assertForbidden();
    expect(app(DashboardSettings::class)->shown()['rightNow'])->toBeTrue();
})->with([
    'open the page' => ['get', 'dashboard-parts.edit'],
    'change the settings' => ['put', 'dashboard-parts.update'],
]);

test('admins see every part turned on until someone turns one off', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('dashboard-parts.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/dashboard-parts')
        ->where('parts', [
            'rightNow' => true,
            'attention' => true,
            'totals' => true,
            'sessionsByDay' => true,
            'sessionsByHour' => true,
            'busiestTimes' => true,
            'closedTime' => true,
        ]));
});

test('admins can turn parts off and on again', function () {
    $admin = User::factory()->admin()->create();

    $off = $this->actingAs($admin)->put(route('dashboard-parts.update'), dashboardPartsForm('busiestTimes', 'closedTime'));
    $shownAfterTurningOff = app(DashboardSettings::class)->shown();
    $this->actingAs($admin)->put(route('dashboard-parts.update'), dashboardPartsForm());

    $off->assertRedirect(route('dashboard-parts.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Dashboard settings saved.']);
    expect($shownAfterTurningOff)->toMatchArray(['busiestTimes' => false, 'closedTime' => false, 'totals' => true])
        ->and(array_filter(app(DashboardSettings::class)->shown()))->toHaveCount(7);
});

test('a choice that is missing, not yes or no, or for no such part is refused', function (array $form, string $field) {
    $response = $this->actingAs(User::factory()->admin()->create())->put(route('dashboard-parts.update'), $form);

    $response->assertSessionHasErrors($field);
    $this->assertDatabaseMissing('settings', ['key' => 'dashboard.hidden_parts']);
})->with([
    'a part left out' => [['parts' => array_diff_key(dashboardPartsForm()['parts'], ['closedTime' => true])], 'parts.closedTime'],
    'a choice that is not yes or no' => [['parts' => ['rightNow' => 'maybe'] + dashboardPartsForm()['parts']], 'parts.rightNow'],
    'a part the dashboard does not have' => [['parts' => dashboardPartsForm()['parts'] + ['weather' => '1']], 'parts'],
]);
