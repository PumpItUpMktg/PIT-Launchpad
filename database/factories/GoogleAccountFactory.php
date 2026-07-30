<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Integrations\Google\GoogleOAuthClient;
use App\Models\GoogleAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleAccount>
 */
class GoogleAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->safeEmail(),
            'credentials' => [
                'access_token' => 'at-'.fake()->sha1(),
                'refresh_token' => 'rt-'.fake()->sha1(),
                // Valid for an hour by default — a live token in tests, no refresh needed.
                'expires_at' => now()->addHour()->format(DATE_ATOM),
            ],
            'scopes' => GoogleOAuthClient::SCOPES,
            'status' => ConnectionStatus::Connected->value,
        ];
    }

    /** A grant whose refresh has failed — the operator must reconnect. */
    public function needsReconnect(): static
    {
        return $this->state(fn () => ['status' => ConnectionStatus::NeedsReconnect->value]);
    }

    /** A grant whose access token has already expired — forces a refresh on next use. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'credentials' => [
                ...$attributes['credentials'],
                'expires_at' => now()->subMinute()->format(DATE_ATOM),
            ],
        ]);
    }
}
