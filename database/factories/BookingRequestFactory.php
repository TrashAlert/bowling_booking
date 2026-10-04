<?php

namespace Database\Factories;

use App\Enums\BookingRequestStatus;
use App\Models\BookingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingRequest>
 */
class BookingRequestFactory extends Factory
{
    /**
     * Define the model's default state: a request for an hour tomorrow
     * evening, still waiting, that can be called about any time.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('01########'),
            'party_size' => 4,
            'minutes' => 60,
            'starts_at' => now()->addDay()->setTime(19, 0),
            'status' => BookingRequestStatus::Pending,
        ];
    }

    /**
     * Indicate the times the customer can be called, in the venue's time zone.
     */
    public function callBetween(string $from, string $until): static
    {
        return $this->state(fn (array $attributes) => [
            'contact_from' => $from,
            'contact_until' => $until,
        ]);
    }
}
