<?php

use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use App\Services\Payments\DepositPaymentProvider;
use App\Services\Payments\PaymentStart;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A provider that works the way a real one does: the customer pays on its
 * own page, and it posts a signed notice once the money has arrived.
 */
class RedirectingPaymentProvider implements DepositPaymentProvider
{
    public function name(): string
    {
        return 'test_bank';
    }

    public function begin(WaitlistDeposit $deposit, string $returnUrl, string $notifyUrl): PaymentStart
    {
        return PaymentStart::redirectTo("https://bank.example/pay/BILL-{$deposit->id}", "BILL-{$deposit->id}");
    }

    public function paidReference(Request $request): ?string
    {
        return $request->input('signature') === 'good' && $request->input('status') === 'paid'
            ? $request->input('bill')
            : null;
    }
}

/**
 * Take deposits through the provider above instead of the stand-in.
 */
function payThroughTheBank(): void
{
    app()->bind(DepositPaymentProvider::class, RedirectingPaymentProvider::class);
}

/**
 * A deposit whose party has pressed Pay and been sent to the bank.
 */
function depositSentToTheBank(): WaitlistDeposit
{
    payThroughTheBank();
    $deposit = WaitlistDeposit::factory()->create();
    test()->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    return $deposit->refresh();
}

test('the stand-in counts a deposit as paid at once and is named on it', function () {
    $deposit = WaitlistDeposit::factory()->create();

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $entry = WaitlistEntry::query()->sole();
    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    expect($deposit->refresh()->only(['payment_provider', 'payment_reference']))->toBe([
        'payment_provider' => 'stand_in',
        'payment_reference' => null,
    ])->and($deposit->isPaid())->toBeTrue();
});

test('the pay page says whether the payment is only a stand-in', function (Closure $provider, bool $standIn) {
    $provider();
    $deposit = WaitlistDeposit::factory()->create();

    $response = $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('payment', ['standIn' => $standIn, 'awaiting' => false]));
})->with([
    'no provider is connected: a stand-in' => [fn () => null, true],
    'a provider is connected: real' => [fn () => payThroughTheBank(), false],
]);

test('with a real provider pressing Pay sends the party to its payment page and not into the line', function () {
    payThroughTheBank();
    $deposit = WaitlistDeposit::factory()->create();

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $response->assertRedirect("https://bank.example/pay/BILL-{$deposit->id}");
    expect($deposit->refresh()->only(['payment_provider', 'payment_reference', 'paid_at']))->toBe([
        'payment_provider' => 'test_bank',
        'payment_reference' => "BILL-{$deposit->id}",
        'paid_at' => null,
    ]);
    $this->assertDatabaseCount('waitlist_entries', 0);
});

test('a party back from the provider is told its payment is awaited until the notice arrives', function () {
    $deposit = depositSentToTheBank();

    $response = $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('waitlist/deposit')
        ->where('payment', ['standIn' => false, 'awaiting' => true]));
});

test('a notice of payment from the provider puts the party in line', function () {
    $deposit = depositSentToTheBank();

    $response = $this->post(route('waitlist.deposit.notice', ['provider' => 'test_bank']), [
        'bill' => $deposit->payment_reference, 'status' => 'paid', 'signature' => 'good',
    ]);

    $response->assertNoContent();
    $entry = WaitlistEntry::query()->sole();
    expect($deposit->refresh()->isPaid())->toBeTrue()
        ->and($deposit->waitlist_entry_id)->toBe($entry->id);
    $this->get(route('waitlist.deposit.show', ['deposit' => $deposit->token]))
        ->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
});

test('a notice that arrives twice keeps the party in line once', function () {
    $deposit = depositSentToTheBank();
    $notice = ['bill' => $deposit->payment_reference, 'status' => 'paid', 'signature' => 'good'];
    $this->post(route('waitlist.deposit.notice', ['provider' => 'test_bank']), $notice);

    $response = $this->post(route('waitlist.deposit.notice', ['provider' => 'test_bank']), $notice);

    $response->assertNoContent();
    $this->assertDatabaseCount('waitlist_entries', 1);
});

test('a notice that proves no payment changes nothing', function (string $provider, array $notice, int $status) {
    $deposit = depositSentToTheBank();

    $response = $this->post(route('waitlist.deposit.notice', ['provider' => $provider]), [
        'bill' => $deposit->payment_reference, 'status' => 'paid', 'signature' => 'good', ...$notice,
    ]);

    $response->assertStatus($status);
    expect($deposit->refresh()->isPaid())->toBeFalse();
    $this->assertDatabaseCount('waitlist_entries', 0);
})->with([
    'the signature is wrong: 400' => ['test_bank', ['signature' => 'forged'], 400],
    'the payment failed: 400' => ['test_bank', ['status' => 'failed'], 400],
    'the payment is not one of ours: 404' => ['test_bank', ['bill' => 'BILL-unknown'], 404],
    'it is sent to a provider that is not in use: 404' => ['stand_in', [], 404],
]);

test('a party that has paid is put in line even if the venue closed meanwhile', function () {
    openDaily('10:00', '23:00');
    $this->travelTo(venueTime('2026-10-05 22:58'));
    $deposit = depositSentToTheBank();
    $this->travelTo(venueTime('2026-10-05 23:02'));

    $response = $this->post(route('waitlist.deposit.notice', ['provider' => 'test_bank']), [
        'bill' => $deposit->payment_reference, 'status' => 'paid', 'signature' => 'good',
    ]);

    $response->assertNoContent();
    expect($deposit->refresh()->isPaid())->toBeTrue();
});

test('pressing Pay again for a deposit that is already paid leads to the same place in line', function () {
    $entry = joinWaitlistOnline();
    $deposit = WaitlistDeposit::query()->sole();

    $response = $this->post(route('waitlist.deposit.store', ['deposit' => $deposit->token]));

    $response->assertRedirect(route('waitlist.show', ['entry' => $entry->token]));
    $this->assertDatabaseCount('waitlist_entries', 1);
});

test('naming a payment provider that is not set up fails with a clear message', function () {
    config(['bowling.deposit_payments.provider' => 'nobody']);

    expect(fn () => app(DepositPaymentProvider::class))
        ->toThrow(InvalidArgumentException::class, 'No deposit payment provider is set up under the name [nobody]');
});
