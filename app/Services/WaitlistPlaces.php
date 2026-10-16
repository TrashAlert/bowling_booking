<?php

namespace App\Services;

use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The places in line a phone has opened, so a customer who closed the page
 * can find their way back to it from the front page.
 *
 * Each time a party's own page is opened, the phone is given a cookie that
 * lists that party among the ones it has seen. Laravel encrypts the cookie,
 * so it can't be edited to reach anyone else's place. It lasts a day, which
 * is longer than anyone waits.
 */
class WaitlistPlaces
{
    private const COOKIE = 'waitlist_places';

    // How long the phone keeps the cookie, and how many places it lists.
    private const COOKIE_MINUTES = 60 * 24;

    private const COOKIE_SIZE = 5;

    /**
     * Note on the phone that it has opened this party's page. Nothing is
     * sent when the phone already knows it.
     */
    public function remember(Request $request, WaitlistEntry $entry): void
    {
        $ids = $this->idsFrom($request);

        if (in_array($entry->id, $ids, true)) {
            return;
        }

        Cookie::queue(self::COOKIE, json_encode(array_slice([...$ids, $entry->id], -self::COOKIE_SIZE)), self::COOKIE_MINUTES);
    }

    /**
     * The places this phone has opened that are still in line, first come
     * first served, each with the address of its page.
     *
     * @return list<array{name: string, status: string, position: int, url: string}>
     */
    public function inLine(Request $request): array
    {
        $ids = $this->idsFrom($request);

        if ($ids === []) {
            return [];
        }

        return WaitlistEntry::query()
            ->whereKey($ids)
            ->inLine()
            ->with('customer')
            ->get()
            ->map(fn (WaitlistEntry $entry) => [
                'name' => $entry->customer->name,
                'status' => $entry->status->value,
                'position' => $entry->position(),
                'url' => route('waitlist.show', ['entry' => $entry->token], absolute: false),
            ])
            ->values()
            ->all();
    }

    /**
     * The ids of the parties listed in the phone's cookie.
     *
     * @return list<int>
     */
    private function idsFrom(Request $request): array
    {
        $ids = json_decode((string) $request->cookie(self::COOKIE), true);

        return is_array($ids) ? array_values(array_filter($ids, is_int(...))) : [];
    }
}
